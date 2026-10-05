<?php

namespace ghoststreet\craftsmartsearch\services;

use Craft;
use craft\web\Request as WebRequest;
use ghoststreet\craftsmartsearch\enums\SearchType;
use ghoststreet\craftsmartsearch\exceptions\SearchException;
use ghoststreet\craftsmartsearch\helpers\ApiResponseHelper;
use ghoststreet\craftsmartsearch\helpers\Logger;
use ghoststreet\craftsmartsearch\helpers\PricingTable;
use ghoststreet\craftsmartsearch\helpers\SearchResultFormatter;
use ghoststreet\craftsmartsearch\helpers\TextValidator;
use ghoststreet\craftsmartsearch\helpers\TimingProfiler;
use ghoststreet\craftsmartsearch\helpers\UsageTracker;
use ghoststreet\craftsmartsearch\models\SearchHistoryEntry;
use ghoststreet\craftsmartsearch\SmartSearch;
use Throwable;
use yii\base\Component;

/**
 * Guarded entry point for callers that are not the REST controller: the Twig
 * variable and the GraphQL resolvers. Applies the same rate limits, budget
 * fallback and history recording the HTTP endpoints enforce, so a search run
 * from a template or from /api counts against the same quotas and shows up in
 * Insights.
 *
 * The static pieces (query, answer, historyEntry, finish, ip, begin) are the ones the
 * REST controller shares. It acquires in beforeAction() and finishes from its own
 * shutdown hook, so it cannot use the instance methods whole, but every step of the
 * flow has one implementation.
 */
class SearchRunner extends Component
{
    public const DEFAULT_SEARCH_LIMIT = 10;

    public const DEFAULT_ANSWER_LIMIT = 5;

    /** What an AI Answer returns when there is nothing to answer. */
    public const EMPTY_ANSWER = ['summary' => null, 'sources' => [], 'confidence' => null, 'budgetExhausted' => false];

    /**
     * @param list<int>|null $sectionIds Sections to restrict to, or null for every section
     * @param bool $withElements Keep each row's Entry, for a caller that needs it (GraphQL)
     * @return array<int, array<string, mixed>> Formatted rows
     */
    public function search(string $query, int $limit, ?int $siteId, ?array $sectionIds, bool $withElements = true): array
    {
        $query = self::query($query);
        if ($query === '') {
            return [];
        }

        $limit = ApiResponseHelper::clampLimit($limit);

        $start = self::begin();
        $token = SmartSearch::getInstance()->rateLimitService->acquire(SearchType::Search, self::ip());

        try {
            $results = SmartSearch::getInstance()->engine()->search($query, $limit, $siteId, $sectionIds);
            $formatted = self::format($results, SearchType::Search, $withElements);

            register_shutdown_function(self::finish(...), $token, self::historyEntry(SearchType::Search, $query, $siteId, $start, count($formatted)));

            return $formatted;
        } catch (Throwable $e) {
            register_shutdown_function(self::finish(...), $token, self::historyEntry(SearchType::Search, $query, $siteId, $start, 0, $e->getMessage()));
            throw $e;
        }
    }

    /**
     * A search made through Craft's own `search` param (NativeSearch): Smart Search's
     * best matches across the queried sites, best first. Recorded like any other, but
     * not rate-limited: it is the site rendering its own page, and a 429 there would
     * break it. A failure is recorded and yields no matches, so the page gets Craft's.
     *
     * @param int[] $siteIds
     * @param list<int>|null $sectionIds Sections to restrict to, or null for every section
     * @return list<string> "elementId-siteId", best first
     */
    public function native(string $query, array $siteIds, ?array $sectionIds): array
    {
        $query = TextValidator::sanitizeQuery($query);
        $historySiteId = count($siteIds) === 1 ? $siteIds[0] : null;
        $start = self::begin();

        try {
            $scores = [];
            if ($query !== '') {
                $pool = SmartSearch::getInstance()->getSettings()->maxSemanticResults;
                foreach ($siteIds as $siteId) {
                    foreach (SmartSearch::getInstance()->engine()->search($query, $pool, $siteId, $sectionIds) as $result) {
                        $scores["{$result['element']->id}-{$siteId}"] = (float)$result['score'];
                    }
                }
                arsort($scores);
            }

            register_shutdown_function(self::finish(...), '', self::historyEntry(SearchType::Native, $query, $historySiteId, $start, count($scores)));

            return array_keys($scores);
        } catch (Throwable $e) {
            Logger::exception($e, 'nativeSearch', ['query' => $query, 'siteIds' => $siteIds]);
            register_shutdown_function(self::finish(...), '', self::historyEntry(SearchType::Native, $query, $historySiteId, $start, 0, $e->getMessage()));

            return [];
        }
    }

    /**
     * @param list<int>|null $sectionIds Sections to restrict retrieval to, or null for every section
     * @param bool $withElements Keep each source's Entry, for a caller that needs it (GraphQL)
     * @return array{summary: ?string, sources: array<int, array<string, mixed>>, confidence: ?string, budgetExhausted: bool}
     */
    public function aiAnswer(string $query, int $limit, ?int $siteId, ?array $sectionIds, bool $withElements = true): array
    {
        $query = self::query($query);
        if ($query === '') {
            return self::EMPTY_ANSWER;
        }

        $limit = ApiResponseHelper::clampLimit($limit);

        $start = self::begin();
        $token = SmartSearch::getInstance()->rateLimitService->acquire(SearchType::AiAnswer, self::ip());
        $exhausted = RateLimitService::isFallbackToken($token);

        try {
            $response = self::answer($query, $limit, $siteId, $sectionIds, $exhausted);
            $sources = self::format($response['sources'], SearchType::AiAnswer, $withElements);

            register_shutdown_function(self::finish(...), $token, self::historyEntry(SearchType::AiAnswer, $query, $siteId, $start, count($sources)));

            return [
                'summary' => $response['summary'],
                'sources' => $sources,
                'confidence' => $response['confidence'],
                'budgetExhausted' => $exhausted,
            ];
        } catch (Throwable $e) {
            register_shutdown_function(self::finish(...), $token, self::historyEntry(SearchType::AiAnswer, $query, $siteId, $start, 0, $e->getMessage()));
            throw $e;
        }
    }

    /**
     * An AI Answer, or plain search results as its sources once the daily budget is spent.
     * Sources are raw engine rows, not yet formatted.
     *
     * @param list<int>|null $sectionIds
     * @return array{summary: ?string, sources: array<int, array<string, mixed>>, confidence: ?string}
     */
    public static function answer(string $query, int $limit, ?int $siteId, ?array $sectionIds, bool $budgetExhausted): array
    {
        if ($budgetExhausted) {
            return [
                'summary' => null,
                'sources' => SmartSearch::getInstance()->engine()->search($query, $limit, $siteId, $sectionIds),
                'confidence' => null,
            ];
        }

        return SmartSearch::getInstance()->aiAnswerService->search($query, $limit, $siteId, $sectionIds);
    }

    /**
     * The same rules the HTTP API applies, for every caller: sanitised, blank means nothing to
     * search, and anything over the length cap is rejected. Checked before a rate-limit slot is taken.
     */
    public static function query(string $raw): string
    {
        $query = TextValidator::sanitizeQuery($raw);

        if (mb_strlen($query) > ApiResponseHelper::MAX_QUERY_LENGTH) {
            throw SearchException::invalidQuery();
        }

        return TextValidator::isEmpty($query) ? '' : $query;
    }

    /**
     * The caller's IP for rate-limiting. A console request has none; a template
     * rendered from a queue job or a command still searches, and still counts,
     * so those share one bucket rather than failing.
     */
    public static function ip(): string
    {
        $request = Craft::$app->getRequest();

        if (!$request instanceof WebRequest) {
            return 'console';
        }

        return (string)($request->getUserIP() ?? '0.0.0.0');
    }

    /** Reset the per-request usage and timing trackers; returns the start time. */
    public static function begin(): float
    {
        UsageTracker::reset();
        TimingProfiler::reset();

        return microtime(true);
    }

    /**
     * The history row for a finished search, priced from what UsageTracker saw during it.
     * Priced here, once, because the tracker is reset by the next search in the same request.
     *
     * The request id and models are kept only in debug mode: no screen reads them, they
     * only help match a row to the log while tuning.
     */
    public static function historyEntry(
        SearchType $type,
        string $query,
        ?int $siteId,
        float $start,
        int $resultsCount,
        ?string $errorMessage = null,
        ?string $requestId = null,
    ): SearchHistoryEntry {
        $usage = UsageTracker::snapshot();
        $debug = Logger::debugMode();

        return new SearchHistoryEntry(
            requestId: $debug ? $requestId : null,
            type: $type->value,
            query: $query,
            siteId: $siteId,
            durationMs: (int)round((microtime(true) - $start) * 1000),
            resultsCount: $resultsCount,
            embeddingTokens: $usage['embeddingTokens'],
            aiAnswerInputTokens: $usage['aiAnswerInputTokens'],
            aiAnswerOutputTokens: $usage['aiAnswerOutputTokens'],
            embeddingCached: $usage['embeddingCached'],
            embeddingModel: $debug ? $usage['embeddingModel'] : null,
            aiAnswerModel: $debug ? $usage['aiAnswerModel'] : null,
            cost: PricingTable::costForUsage(),
            errorMessage: $errorMessage,
        );
    }

    /**
     * Record the search, release the rate-limit slot and charge what the search spent to the
     * daily budget. Run at shutdown, after the response is on the wire: each step costs a
     * database round trip and none shapes what the caller gets back.
     *
     * With no entry, nothing is recorded but the request may still have spent, so the
     * cost is read from what UsageTracker saw.
     */
    public static function finish(string $token, ?SearchHistoryEntry $entry): void
    {
        $plugin = SmartSearch::getInstance();

        if ($entry !== null) {
            $plugin->historyService->record($entry);
        }

        $plugin->rateLimitService->release($token);
        $plugin->rateLimitService->recordCost($entry?->cost ?? PricingTable::costForUsage());
    }

    /**
     * Format results, optionally putting each row's Entry back: formatMany drops `element`.
     *
     * @param array<int, array<string, mixed>> $results
     * @return array<int, array<string, mixed>>
     */
    private static function format(array $results, SearchType $type, bool $withElements): array
    {
        $formatted = SearchResultFormatter::formatMany($results, $type);

        if (!$withElements) {
            return $formatted;
        }

        return array_map(
            static fn(array $row, array $result): array => $row + ['element' => $result['element']],
            $formatted,
            array_values($results),
        );
    }
}

<?php

namespace ghoststreet\craftsmartsearch\variables;

use ghoststreet\craftsmartsearch\exceptions\ErrorCode;
use ghoststreet\craftsmartsearch\exceptions\SearchException;
use ghoststreet\craftsmartsearch\exceptions\SmartSearchException;
use ghoststreet\craftsmartsearch\helpers\Logger;
use ghoststreet\craftsmartsearch\helpers\RequestParameterExtractor;
use ghoststreet\craftsmartsearch\services\SearchRunner;
use ghoststreet\craftsmartsearch\SmartSearch;

/**
 * Twig variable class for Smart Search.
 *
 * Provides `craft.smartSearch.search()` and `craft.smartSearch.aiAnswer()` for frontend templates.
 * Both return the same result shape as the HTTP API, so a template and a headless
 * front end read the same field names.
 *
 * Both run through SearchRunner, so a template search obeys the same rate limits
 * and daily AI budget as the HTTP endpoints and lands in Insights.
 */
class SmartSearchVariable
{
    /**
     * Failures a template should shrug off with an empty result rather than an error page:
     * the visitor is over a limit, no provider key is set, or the query is not searchable.
     */
    private const SOFT_FAILURES = [
        ErrorCode::RATE_LIMIT_REQUESTS,
        ErrorCode::RATE_LIMIT_CONCURRENCY,
        ErrorCode::CONFIG_MISSING_API_KEY,
        ErrorCode::SEARCH_VALIDATION_FAILED,
    ];

    public function search(string $query, int $limit = SearchRunner::DEFAULT_SEARCH_LIMIT, ?int $siteId = null, array|string|null $sections = null): array
    {
        $handles = RequestParameterExtractor::normalizeSections($sections);

        try {
            return SmartSearch::getInstance()->searchRunner->search(
                $query,
                $limit,
                self::siteId($siteId),
                $handles === [] ? null : RequestParameterExtractor::sectionIds($handles),
                withElements: false,
            );
        } catch (SmartSearchException $e) {
            return self::soften($e, 'twigSearch', []);
        }
    }

    public function aiAnswer(string $query, int $limit = SearchRunner::DEFAULT_ANSWER_LIMIT, ?int $siteId = null): array
    {
        try {
            return SmartSearch::getInstance()->searchRunner->aiAnswer($query, $limit, self::siteId($siteId), null, withElements: false);
        } catch (SmartSearchException $e) {
            return self::soften($e, 'twigAiAnswer', SearchRunner::EMPTY_ANSWER);
        }
    }

    /** No siteId means every site; otherwise the HTTP API's rules apply, so 0 is every site too. */
    private static function siteId(?int $siteId): ?int
    {
        if ($siteId === null) {
            return null;
        }

        [$resolved, $valid] = RequestParameterExtractor::resolveSiteId($siteId);

        if (!$valid) {
            throw SearchException::invalidSiteId();
        }

        return $resolved;
    }

    private static function soften(SmartSearchException $e, string $operation, array $empty): array
    {
        if (!in_array($e->errorCode(), self::SOFT_FAILURES, true)) {
            throw $e;
        }

        Logger::exception($e, $operation, ['code' => $e->errorCode()->value]);

        return $empty;
    }
}

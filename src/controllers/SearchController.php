<?php

namespace ghoststreet\craftsmartsearch\controllers;

use Craft;
use Generator;
use ghoststreet\craftsmartsearch\enums\SearchType;
use ghoststreet\craftsmartsearch\exceptions\SearchException;
use ghoststreet\craftsmartsearch\exceptions\SmartSearchException;
use ghoststreet\craftsmartsearch\filters\SmartSearchCors;
use ghoststreet\craftsmartsearch\helpers\ApiResponseHelper;
use ghoststreet\craftsmartsearch\helpers\Logger;
use ghoststreet\craftsmartsearch\helpers\RequestParameterExtractor;
use ghoststreet\craftsmartsearch\helpers\SearchResultFormatter;
use ghoststreet\craftsmartsearch\helpers\TimingProfiler;
use ghoststreet\craftsmartsearch\models\SearchHistoryEntry;
use ghoststreet\craftsmartsearch\services\RateLimitService;
use ghoststreet\craftsmartsearch\services\SearchRunner;
use ghoststreet\craftsmartsearch\SmartSearch;
use Throwable;
use yii\web\Response;

/**
 * Search controller.
 *
 * Public, anonymous-friendly. Two auth modes coexist:
 *   - Same-origin browser callers (CP Preview, Twig-rendered front-end pages)
 *     pass Craft's CSRF token.
 *   - Cross-origin and server-to-server callers present
 *     `Authorization: Bearer <apiToken>`. Yii's own CSRF check is off because it
 *     runs before beforeAction() and cannot see the bearer; beforeAction() checks
 *     CSRF itself for every request without a valid bearer. Bearer is a
 *     non-cookie credential, so CSRF adds nothing on top of it.
 *
 * Origin/Referer is constrained to the site host plus the `allowedOrigins`
 * setting. The SmartSearchCors behavior emits `Access-Control-*` response
 * headers for the same allowlist so browser CORS preflights succeed. Per-IP
 * rate limits, AI Answer concurrency caps, and daily cost budgets are enforced
 * by RateLimitService.
 */
class SearchController extends BaseApiController
{
    public $defaultAction = 'search';

    protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_LIVE;

    public $enableCsrfValidation = false;

    /** Monotonic start time for total-request timing. */
    private float $startTime = 0.0;

    /** Release token from RateLimitService::acquire(); passed to release() at shutdown. */
    private string $rateLimitToken = '';

    /** History row captured during the request, written after the response is sent. */
    private ?SearchHistoryEntry $pendingHistory = null;

    /** Set true once a valid bearer token has been verified for this request. */
    private bool $bearerAuthenticated = false;

    public function behaviors(): array
    {
        return array_merge(parent::behaviors(), [
            'cors' => [
                'class' => SmartSearchCors::class,
                'cors' => [
                    'Origin' => [],
                    'Access-Control-Request-Method' => ['GET', 'POST', 'OPTIONS'],
                    'Access-Control-Request-Headers' => ['Authorization', 'Content-Type', 'X-CSRF-Token'],
                    'Access-Control-Allow-Credentials' => null,
                    'Access-Control-Max-Age' => 86400,
                ],
            ],
        ]);
    }

    /**
     * Gate every request through bearer/CSRF auth, origin allowlist, and the
     * per-action rate limiter. Stamps the request with a correlation id.
     *
     * Auth model:
     * - Bearer token authenticates cross-origin and S2S callers. When a valid
     *   token is presented, CSRF is skipped because the bearer is a stronger,
     *   non-cookie credential that CSRF can't add anything to.
     * - Same-origin browser callers without a bearer still require CSRF.
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->startTime = SearchRunner::begin();

        $request = Craft::$app->getRequest();

        try {
            $this->enforceBearerTokenIfConfigured($request);

            if (!$this->bearerAuthenticated) {
                $this->requireCsrfToken($request);
            }

            $this->enforceOriginAllowlist($request);

            $this->rateLimitToken = SmartSearch::getInstance()->rateLimitService->acquire(
                $this->resolveSearchType($action->id),
                SearchRunner::ip(),
            );
        } catch (SmartSearchException $e) {
            $this->jsonError($e, 'request rejected', ['ip' => $request->getUserIP(), 'method' => $request->getMethod()]);
            Craft::$app->end();
        }

        register_shutdown_function(fn() => SearchRunner::finish($this->rateLimitToken, $this->pendingHistory));

        return true;
    }

    private function requireCsrfToken($request): void
    {
        if (!Craft::$app->getConfig()->getGeneral()->enableCsrfProtection) {
            return;
        }

        $tokenName = Craft::$app->getConfig()->getGeneral()->csrfTokenName;
        $presented = (string)(
            $request->getHeaders()->get('X-CSRF-Token')
            ?? $request->getParam($tokenName)
            ?? ''
        );


        $security = Craft::$app->getSecurity();

        if ($presented === '' || !$security->compareString(
            $security->unmaskToken($request->getCsrfToken()),
            $security->unmaskToken($presented),
        )) {
            throw SearchException::csrfRejected();
        }
    }

    /**
     * Reject cross-origin requests that aren't on the configured allowlist.
     *
     * Header-less requests (no Origin and no Referer: local dev, same-server
     * curl, CI) pass only when no allowlist is set, or when apiToken auth
     * (validated upstream in beforeAction) proves an S2S caller. Once an
     * allowlist is configured without apiToken, header-less requests are
     * rejected because they cannot be matched.
     */
    private function enforceOriginAllowlist($request): void
    {
        $origin = (string)$request->getHeaders()->get('Origin');
        $referer = (string)$request->getHeaders()->get('Referer');
        $candidate = $origin !== '' ? $origin : $referer;

        if ($candidate === '') {
            $settings = SmartSearch::getInstance()->getSettings();
            if (empty($settings->getAllowedOriginsList()) || $this->bearerAuthenticated) {
                return;
            }
            throw SearchException::originNotAllowed('');
        }

        $candidateHost = self::normalizeOriginUrl($candidate);
        $siteHost = $request->getHostInfo();

        if ($candidateHost === $siteHost) {
            return;
        }

        $allowed = SmartSearch::getInstance()->getSettings()->getAllowedOriginsList();
        if (in_array($candidateHost, $allowed, true)) {
            return;
        }

        throw SearchException::originNotAllowed($candidateHost);
    }

    /**
     * Reduce an Origin or Referer URL to the canonical "scheme://host[:port]"
     * form used to compare against the configured allowlist.
     */
    private static function normalizeOriginUrl(string $url): string
    {
        $parts = parse_url($url);
        $host = ($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? '');
        if (!empty($parts['port'])) {
            $host .= ':' . $parts['port'];
        }
        return $host;
    }

    /**
     * Authenticate a presented bearer token. A valid token marks the caller as
     * server-to-server and lets beforeAction() skip CSRF. A request with no
     * bearer is left for the CSRF path, so same-origin callers (CP Preview,
     * Twig pages) keep working when an apiToken is set. Only a wrong token is
     * rejected here.
     */
    private function enforceBearerTokenIfConfigured($request): void
    {
        $token = SmartSearch::getInstance()->getSettings()->getApiToken();

        if (empty($token)) {
            return;
        }

        $authorization = (string)$request->getHeaders()->get('Authorization');
        if (!str_starts_with($authorization, 'Bearer ')) {
            return;
        }

        if (hash_equals($token, substr($authorization, 7))) {
            $this->bearerAuthenticated = true;
            return;
        }

        throw SearchException::invalidApiToken();
    }

    /** Map an action id to the rate-limit bucket it spends from. */
    private function resolveSearchType(string $actionId): SearchType
    {
        return match ($actionId) {
            'ai-answer' => SearchType::AiAnswer,
            'ai-answer-stream' => SearchType::AiAnswerStream,
            default => SearchType::Search,
        };
    }

    /**
     * GET /actions/smart-search/search
     *
     * The search endpoint: `q` plus options (`limit`, `siteId`, `sections`).
     * Returns hybrid semantic + keyword results as JSON, no LLM call.
     */
    public function actionSearch(): Response
    {
        $this->requireAcceptsJson();
        $params = RequestParameterExtractor::extractSearchParams();

        if ($params['validationError'] !== null) {
            return $this->badRequest($params['validationError']);
        }

        $this->logRequest('search', $params);

        try {
            $results = SmartSearch::getInstance()->engine()->search(
                $params['query'],
                $params['limit'],
                $params['siteId'],
                $params['sectionIds'],
            );

            $formattedResults = SearchResultFormatter::formatMany($results, SearchType::Search);

            $this->recordHistory(SearchType::Search, $params, count($formattedResults));

            return $this->successResponse('search', [
                'query' => $params['query'],
                'results' => $formattedResults,
                'count' => count($formattedResults),
            ]);
        } catch (Throwable $e) {
            $this->recordHistory(SearchType::Search, $params, 0, $e->getMessage());
            return $this->jsonError($e, 'search', $params);
        }
    }

    /**
     * POST /actions/smart-search/search/ai-answer
     *
     * AI Answer with citations, returned as one JSON body. POST because it
     * spends OpenAI credit. Once the daily budget is spent it returns the same
     * shape with `summary: null, confidence: null, budgetExhausted: true`.
     */
    public function actionAiAnswer(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $params = RequestParameterExtractor::extractSearchParams(20);

        if ($params['validationError'] !== null) {
            return $this->badRequest($params['validationError']);
        }

        $exhausted = RateLimitService::isFallbackToken($this->rateLimitToken);
        $this->logRequest($exhausted ? 'aiAnswerSearchFallback' : 'aiAnswer', $params);

        try {
            $response = SearchRunner::answer($params['query'], $params['limit'], $params['siteId'], $params['sectionIds'], $exhausted);
            $formattedSources = SearchResultFormatter::formatMany($response['sources'], SearchType::AiAnswer);

            $this->recordHistory(SearchType::AiAnswer, $params, count($formattedSources));

            return $this->successResponse('aiAnswer', [
                'query' => $params['query'],
                'summary' => $response['summary'],
                'sources' => $formattedSources,
                'count' => count($formattedSources),
                'confidence' => $response['confidence'],
            ] + ($exhausted ? ['budgetExhausted' => true] : []));
        } catch (Throwable $e) {
            $this->recordHistory(SearchType::AiAnswer, $params, 0, $e->getMessage());
            return $this->jsonError($e, 'aiAnswer', $params);
        }
    }

    /**
     * GET /actions/smart-search/search/ai-answer-stream
     *
     * The same answer over Server-Sent Events:
     *   event: sources  data: {sources: [...]}
     *   event: token    data: {t: "..."}
     *   event: done     data: {}
     *   event: error    data: {message: "..."}
     *
     * GET because EventSource cannot POST. CSRF is still enforced via
     * requireCsrfToken() in beforeAction; the widget passes the token in the
     * query string, which an <img src> or cross-origin attacker cannot forge.
     */
    public function actionAiAnswerStream(): Response
    {
        $params = RequestParameterExtractor::extractSearchParams(20);

        Craft::$app->getSession()->close();

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->getHeaders()
            ->set('Content-Type', 'text/event-stream')
            ->set('Cache-Control', 'no-cache')
            ->set('X-Accel-Buffering', 'no')
            ->set('Connection', 'keep-alive');
        $response->stream = fn(): Generator => $this->sseEvents($params);

        return $response;
    }

    /**
     * The SSE body for Response::$stream, which sends the headers once and then echoes and
     * flushes each yielded chunk. PHP's output buffers are closed first, or a flush would
     * only fill output_buffering instead of reaching the client.
     *
     * @return Generator<int, string>
     */
    private function sseEvents(array $params): Generator
    {
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @ob_implicit_flush(true);

        yield "retry: 86400000\n\n: connected\n\n";

        if ($params['validationError'] !== null) {
            yield self::sse('error', $params['validationError']);
        } elseif (RateLimitService::isFallbackToken($this->rateLimitToken)) {
            yield from $this->aiAnswerStreamFallback($params);
        } else {
            $this->logRequest('aiAnswerStream', $params);
            [$sourceCount, $errorMessage] = yield from $this->runStreamLoop($params);
            $this->recordHistory(SearchType::AiAnswer, $params, $sourceCount, $errorMessage);
        }
    }

    /**
     * Pump the AI Answer generator to SSE. Returns [sourceCount, errorMessage|null] for history.
     *
     * @return Generator<int, string, mixed, array{int, ?string}>
     */
    private function runStreamLoop(array $params): Generator
    {
        $sourceCount = 0;

        try {
            $generator = SmartSearch::getInstance()->aiAnswerService->searchStream(
                $params['query'],
                $params['limit'],
                $params['siteId'],
                $params['sectionIds'],
            );

            foreach ($generator as $event) {
                switch ($event['type']) {
                    case 'sources':
                        $formatted = SearchResultFormatter::formatMany($event['sources'], SearchType::AiAnswerStream);
                        $sourceCount = count($formatted);
                        yield self::sse('sources', ['sources' => $formatted, 'requestId' => $this->requestId]);
                        break;
                    case 'token':
                        yield self::sse('token', ['t' => $event['text']]);
                        break;
                    case 'done':
                        yield self::sse('done', ['requestId' => $this->requestId]);
                        break;
                }

                if (connection_aborted()) {
                    break;
                }
            }
            return [$sourceCount, null];
        } catch (Throwable $e) {
            yield self::sse('error', ApiResponseHelper::error($e, 'aiAnswerStream', $this->errorContext($params)));
            return [$sourceCount, $e->getMessage()];
        }
    }

    /**
     * Budget-exhausted SSE fallback. Emits `sources` with `budgetExhausted: true`
     * then `done`; no `token` events. Frontend should skip waiting for tokens
     * when sources carries budgetExhausted=true.
     *
     * @return Generator<int, string>
     */
    private function aiAnswerStreamFallback(array $params): Generator
    {
        $this->logRequest('aiAnswerStreamFallback', $params);

        $formattedSources = [];
        $errorMessage = null;

        try {
            $results = SearchRunner::answer($params['query'], $params['limit'], $params['siteId'], $params['sectionIds'], true)['sources'];
            $formattedSources = SearchResultFormatter::formatMany($results, SearchType::AiAnswer);
            yield self::sse('sources', [
                'sources' => $formattedSources,
                'budgetExhausted' => true,
                'requestId' => $this->requestId,
            ]);
            yield self::sse('done', ['requestId' => $this->requestId]);
        } catch (Throwable $e) {
            $errorMessage = $e->getMessage();
            yield self::sse('error', ApiResponseHelper::error($e, 'aiAnswerStreamFallback', $this->errorContext($params)));
        }

        $this->recordHistory(SearchType::AiAnswer, $params, count($formattedSources), $errorMessage);
    }

    private static function sse(string $event, array $data): string
    {
        return "event: {$event}\n"
            . 'data: ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
    }

    private function logRequest(string $action, array $params): void
    {
        Logger::debug('search request', [
            'requestId' => $this->requestId,
            'action' => $action,
            'q' => mb_substr($params['query'], 0, 100),
            'limit' => $params['limit'],
            'siteId' => $params['siteId'],
            'sections' => $params['sections'],
        ]);
    }

    private function successResponse(string $action, array $body): Response
    {
        $elapsedMs = (int)round((microtime(true) - $this->startTime) * 1000);

        Logger::timing("{$action} response", $elapsedMs, [
            'requestId' => $this->requestId,
            'results' => $body['count'],
        ]);

        return $this->asJson(
            ['success' => true, 'requestId' => $this->requestId]
            + $body
            + $this->diagnostics($elapsedMs)
        );
    }

    /**
     * Per-phase timings and peak memory, for the Preview page's comparison panel and the
     * comparison harness.
     *
     * Only when SMART_SEARCH_DEBUG is on: the phase names describe the plugin's internals,
     * which is useful to whoever is tuning it and nobody else.
     *
     * @return array<string, mixed>
     */
    private function diagnostics(int $elapsedMs): array
    {
        if (!Logger::debugMode()) {
            return [];
        }

        return [
            'timings' => array_merge(TimingProfiler::phases(), [['name' => 'Total', 'ms' => $elapsedMs]]),
        ];
    }

    /**
     * Capture the history row for the shutdown hook to write, priced from what UsageTracker
     * saw during the search. Searches from the CP are the admin previewing, not visitors.
     */
    private function recordHistory(SearchType $type, array $params, int $resultsCount, ?string $errorMessage = null): void
    {
        if (Craft::$app->getRequest()->getIsCpRequest()) {
            return;
        }

        $this->pendingHistory = SearchRunner::historyEntry(
            $type,
            $params['query'],
            $params['siteId'],
            $this->startTime,
            $resultsCount,
            $errorMessage,
            $this->requestId,
        );
    }

    /** A visitor's query can be long; the log keeps its start. */
    protected function errorContext(array $extra = []): array
    {
        if (isset($extra['query'])) {
            $extra['query'] = mb_substr($extra['query'], 0, 100);
        }

        return parent::errorContext($extra);
    }
}

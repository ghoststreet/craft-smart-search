<?php

namespace ghoststreet\craftsmartsearch\controllers;

use Craft;
use ghoststreet\craftsmartsearch\exceptions\RateLimitException;
use ghoststreet\craftsmartsearch\filters\SmartSearchCors;
use ghoststreet\craftsmartsearch\helpers\ApiResponseHelper;
use ghoststreet\craftsmartsearch\helpers\ErrorMapper;
use ghoststreet\craftsmartsearch\helpers\Logger;
use ghoststreet\craftsmartsearch\helpers\PricingTable;
use ghoststreet\craftsmartsearch\helpers\RequestParameterExtractor;
use ghoststreet\craftsmartsearch\helpers\SearchResultFormatter;
use ghoststreet\craftsmartsearch\helpers\UsageTracker;
use ghoststreet\craftsmartsearch\models\SearchHistoryEntry;
use ghoststreet\craftsmartsearch\services\RateLimitService;
use ghoststreet\craftsmartsearch\SmartSearch;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;
use yii\web\UnauthorizedHttpException;

/**
 * Search controller.
 *
 * Public, anonymous-friendly. Two auth modes coexist:
 *   - Same-origin browser callers (CP Preview, Twig-rendered front-end pages)
 *     pass Craft's CSRF token.
 *   - Cross-origin and server-to-server callers present
 *     `Authorization: Bearer <apiToken>`. CSRF validation is enabled by default
 *     at the class level and bypassed manually in beforeAction() once a valid
 *     bearer token is present — bearer is a non-cookie credential, so CSRF
 *     adds nothing on top of it.
 *
 * Origin/Referer is constrained to the site host plus the `allowedOrigins`
 * setting. The SmartSearchCors behavior emits `Access-Control-*` response
 * headers for the same allowlist so browser CORS preflights succeed. Per-IP
 * rate limits, AI Answer concurrency caps, and daily cost budgets are enforced
 * by RateLimitService.
 */
class SearchController extends BaseApiController
{
    public $defaultAction = 'semantic-search';

    protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_LIVE;

    public $enableCsrfValidation = true;

    /** Monotonic start time for total-request timing. */
    private float $startTime = 0.0;

    /** Release token from RateLimitService::acquire(); passed to release() in afterAction. */
    private string $rateLimitToken = '';

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

        $this->startTime = microtime(true);
        UsageTracker::reset();

        $request = Craft::$app->getRequest();

        $this->enforceBearerTokenIfConfigured($request);

        if (!$this->bearerAuthenticated) {
            $this->requireCsrfToken($request);
        }

        $this->enforceOriginAllowlist($request);

        $kind = match ($action->id) {
            'ai-answer', 'ai-answer-stream' => RateLimitService::KIND_AI_ANSWER,
            default => RateLimitService::KIND_SEARCH,
        };

        $ip = (string)($request->getUserIP() ?? '0.0.0.0');

        try {
            $this->rateLimitToken = SmartSearch::getInstance()->rateLimitService->acquire($kind, $ip);
        } catch (RateLimitException $e) {
            $this->emitRateLimitResponse($e);
            return false;
        }

        register_shutdown_function(function(): void {
            $this->releaseRateLimit();
        });

        return true;
    }

    public function afterAction($action, $result)
    {
        $this->releaseRateLimit();
        return parent::afterAction($action, $result);
    }

    private function releaseRateLimit(): void
    {
        if ($this->rateLimitToken === '') {
            return;
        }

        $rl = SmartSearch::getInstance()->rateLimitService;
        $rl->release($this->rateLimitToken);

        $usage = UsageTracker::snapshot();
        $cost = PricingTable::costForUsage(
            $usage['embeddingModel'],
            (int)$usage['embeddingTokens'],
            $usage['aiAnswerModel'],
            (int)$usage['aiAnswerInputTokens'],
            (int)$usage['aiAnswerOutputTokens'],
        );
        if ($cost > 0) {
            $ip = (string)(Craft::$app->getRequest()->getUserIP() ?? '0.0.0.0');
            $rl->recordCost($ip, $cost);
        }

        $this->rateLimitToken = '';
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

        if ($presented === '' || !$request->validateCsrfToken($presented)) {
            Logger::warning('csrf rejected', [
                'requestId' => $this->requestId,
                'ip' => $request->getUserIP(),
                'method' => $request->getMethod(),
            ]);
            throw new BadRequestHttpException('Missing or invalid CSRF token.');
        }
    }

    /**
     * Reject cross-origin requests that aren't on the configured allowlist.
     *
     * Header-less requests (no Origin and no Referer — local dev, same-server
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
            if (empty($settings->getAllowedOriginsList()) || !empty($settings->getApiToken())) {
                return;
            }
            Logger::warning('origin rejected: no Origin/Referer header against configured allowlist', [
                'requestId' => $this->requestId,
                'ip' => $request->getUserIP(),
            ]);
            throw new ForbiddenHttpException('Origin or Referer header required.');
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

        Logger::warning('origin rejected', [
            'requestId' => $this->requestId,
            'origin' => $candidateHost,
            'expected' => $siteHost,
        ]);
        throw new ForbiddenHttpException('Origin not allowed.');
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

    private function enforceBearerTokenIfConfigured($request): void
    {
        $token = SmartSearch::getInstance()->getSettings()->getApiToken();

        if (empty($token)) {
            return;
        }

        $authorization = (string)$request->getHeaders()->get('Authorization');
        $presented = str_starts_with($authorization, 'Bearer ')
            ? substr($authorization, 7)
            : '';

        if ($presented !== '' && hash_equals($token, $presented)) {
            $this->bearerAuthenticated = true;
            return;
        }

        Logger::warning('bearer auth failed', [
            'requestId' => $this->requestId,
            'ip' => $request->getUserIP(),
        ]);

        throw new UnauthorizedHttpException('Invalid or missing API token.');
    }

    private function emitRateLimitResponse(RateLimitException $e): void
    {
        $response = Craft::$app->getResponse();
        $response->getHeaders()->set('Retry-After', (string)$e->retryAfterSeconds);
        $response->format = Response::FORMAT_JSON;
        $response->data = ApiResponseHelper::error($e, 'rateLimit', ['requestId' => $this->requestId]);
        $response->setStatusCode($e->httpStatus());
    }

    /** Hybrid semantic + keyword search fused with RRF. No LLM call. */
    public function actionSearch(): Response
    {
        $this->requireAcceptsJson();
        $params = RequestParameterExtractor::extractSearchParams();

        if ($params['validationError'] !== null) {
            return $this->asJson($this->withRequestId($params['validationError']))->setStatusCode(400);
        }

        $this->logRequest('semanticSearch', $params);

        try {
            $results = SmartSearch::getInstance()->searchService->search(
                $params['query'],
                $params['limit'],
                $params['siteId']
            );

            $formattedResults = $this->formatSearchResults($results, SearchResultFormatter::TYPE_SMART);

            Logger::info('semanticSearch endpoint response', [
                'requestId' => $this->requestId,
                'rawResultsFromService' => count($results),
                'afterFormatting' => count($formattedResults),
                'firstResultElementId' => $results[0]['element']->id ?? null,
                'firstResultScore' => $results[0]['score'] ?? null,
                'firstFormattedKeys' => isset($formattedResults[0]) ? array_keys($formattedResults[0]) : null,
                'firstFormattedSample' => $formattedResults[0] ?? null,
                'payloadShape' => ['query', 'semanticResults', 'semanticCount'],
            ]);

            $this->recordHistory('smart', $params, count($formattedResults));

            return $this->successResponse('semanticSearch', [
                'query' => $params['query'],
                'semanticResults' => $formattedResults,
                'semanticCount' => count($formattedResults),
            ]);
        } catch (Throwable $e) {
            $this->recordHistory('smart', $params, 0, $e->getMessage());
            return ApiResponseHelper::jsonError($this, $e, 'semanticSearch', $this->errorContext($params));
        }
    }

    /** Synchronous AI Answer (full JSON in one shot). Streams via actionAiAnswerStream. */
    public function actionAiAnswer(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $params = RequestParameterExtractor::extractSearchParams(20);

        if ($params['validationError'] !== null) {
            return $this->asJson($this->withRequestId($params['validationError']))->setStatusCode(400);
        }

        if (RateLimitService::isFallbackToken($this->rateLimitToken)) {
            return $this->aiAnswerFallback($params);
        }

        $this->logRequest('aiAnswer', $params);

        try {
            $response = SmartSearch::getInstance()->aiAnswerService->search(
                $params['query'],
                $params['limit'],
                $params['siteId']
            );

            $formattedSources = $this->formatElementResults(
                array_column($response['sources'], 'element'),
                $response['sources'],
                SearchResultFormatter::TYPE_AI_ANSWER
            );

            Logger::info('aiAnswer endpoint response', [
                'requestId' => $this->requestId,
                'rawSourcesFromService' => count($response['sources'] ?? []),
                'afterFormatting' => count($formattedSources),
                'summaryLength' => strlen($response['summary'] ?? ''),
                'confidence' => $response['confidence'] ?? null,
                'firstFormattedKeys' => isset($formattedSources[0]) ? array_keys($formattedSources[0]) : null,
                'firstFormattedSample' => $formattedSources[0] ?? null,
            ]);

            $this->recordHistory('aiAnswer', $params, count($formattedSources));

            return $this->successResponse('aiAnswer', [
                'query' => $params['query'],
                'summary' => $response['summary'] ?? null,
                'sources' => $formattedSources,
                'count' => count($formattedSources),
                'confidence' => $response['confidence'] ?? null,
            ]);
        } catch (Throwable $e) {
            $this->recordHistory('aiAnswer', $params, 0, $e->getMessage());
            return ApiResponseHelper::jsonError($this, $e, 'aiAnswer', $this->errorContext($params));
        }
    }

    /**
     * Streaming AI Answer endpoint. Emits Server-Sent Events:
     *   event: sources  data: {sources: [...]}
     *   event: token    data: {t: "..."}
     *   event: done     data: {}
     *   event: error    data: {message: "..."}
     *
     * Accepts GET because EventSource cannot POST. CSRF is still enforced via
     * requireCsrfToken() in beforeAction — the widget passes the token in the
     * query string, which an <img src> or cross-origin attacker cannot forge.
     */
    public function actionAiAnswerStream(): Response
    {
        $params = RequestParameterExtractor::extractSearchParams(20);
        $response = $this->beginSseResponse();

        if ($params['validationError'] !== null) {
            $this->emitSse('error', $params['validationError']);
        } elseif (RateLimitService::isFallbackToken($this->rateLimitToken)) {
            $this->aiAnswerStreamFallback($params);
            $this->releaseRateLimit();
        } else {
            $this->logRequest('ragStream', $params);
            [$sourceCount, $errorMessage] = $this->runStreamLoop($params);
            $this->recordHistory('aiAnswer', $params, $sourceCount, $errorMessage);
            $this->releaseRateLimit();
        }

        Craft::$app->end();
        return $response;
    }

    /** Switch the response to SSE: raw format, headers, drain buffers, emit `: connected`. */
    private function beginSseResponse(): Response
    {
        Craft::$app->getSession()->close();

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->content = '';

        // Suppressed: non-removable buffers (zlib, user-cache) raise notices we can't avoid.
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @ob_implicit_flush(true);

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');

        echo ": connected\n\n";
        @flush();

        return $response;
    }

    /** Pump the AI Answer generator to SSE. Returns [sourceCount, errorMessage|null] for history. */
    private function runStreamLoop(array $params): array
    {
        $sourceCount = 0;

        try {
            $generator = SmartSearch::getInstance()->aiAnswerService->searchStream(
                $params['query'],
                $params['limit'],
                $params['siteId']
            );

            foreach ($generator as $event) {
                switch ($event['type']) {
                    case 'sources':
                        $formatted = $this->formatElementResults(
                            array_column($event['sources'], 'element'),
                            $event['sources'],
                            SearchResultFormatter::TYPE_AI_ANSWER
                        );
                        $sourceCount = count($formatted);
                        Logger::info('ragStream sources emit', [
                            'requestId' => $this->requestId,
                            'rawSourcesFromService' => count($event['sources'] ?? []),
                            'afterFormatting' => $sourceCount,
                            'firstFormattedKeys' => isset($formatted[0]) ? array_keys($formatted[0]) : null,
                            'firstFormattedSample' => $formatted[0] ?? null,
                        ]);
                        $this->emitSse('sources', ['sources' => $formatted, 'requestId' => $this->requestId]);
                        break;
                    case 'token':
                        $this->emitSse('token', ['t' => $event['text']]);
                        break;
                    case 'done':
                        $this->emitSse('done', ['requestId' => $this->requestId]);
                        break;
                }

                if (connection_aborted()) {
                    break;
                }
            }
            return [$sourceCount, null];
        } catch (Throwable $e) {
            $code = ErrorMapper::codeFor($e);
            Logger::exception($e, 'ragStream', $this->errorContext($params) + ['code' => $code->value]);
            $payload = [
                'code' => $code->value,
                'message' => ErrorMapper::translatedMessage($e) . " (Reference: {$this->requestId})",
                'requestId' => $this->requestId,
            ];
            if ($e instanceof RateLimitException) {
                $payload['retryAfter'] = $e->retryAfterSeconds;
            }
            $this->emitSse('error', $payload);
            return [$sourceCount, $e->getMessage()];
        }
    }

    /**
     * Budget-exhausted JSON fallback. Returns the normal AI Answer shape with
     * `summary: null, confidence: null, budgetExhausted: true`. Frontend
     * branches on `response.budgetExhausted === true`.
     */
    private function aiAnswerFallback(array $params): Response
    {
        $this->logRequest('aiAnswerSearchFallback', $params);

        try {
            $results = SmartSearch::getInstance()->searchService->search(
                $params['query'],
                $params['limit'],
                $params['siteId']
            );

            $formattedSources = $this->formatSearchResults($results, SearchResultFormatter::TYPE_AI_ANSWER);

            $this->recordHistory('aiAnswer', $params, count($formattedSources));

            return $this->successResponse('aiAnswer', [
                'query' => $params['query'],
                'summary' => null,
                'sources' => $formattedSources,
                'count' => count($formattedSources),
                'confidence' => null,
                'budgetExhausted' => true,
            ]);
        } catch (Throwable $e) {
            $this->recordHistory('aiAnswer', $params, 0, $e->getMessage());
            return ApiResponseHelper::jsonError($this, $e, 'aiAnswer', $this->errorContext($params));
        }
    }

    /**
     * Budget-exhausted SSE fallback. Emits `sources` with `budgetExhausted: true`
     * then `done`; no `token` events. Frontend should skip waiting for tokens
     * when sources carries budgetExhausted=true.
     */
    private function aiAnswerStreamFallback(array $params): void
    {
        $this->logRequest('ragStreamFallback', $params);

        $formattedSources = [];
        $errorMessage = null;

        try {
            $results = SmartSearch::getInstance()->searchService->search(
                $params['query'],
                $params['limit'],
                $params['siteId']
            );
            $formattedSources = $this->formatSearchResults($results, SearchResultFormatter::TYPE_AI_ANSWER);
            $this->emitSse('sources', [
                'sources' => $formattedSources,
                'budgetExhausted' => true,
                'requestId' => $this->requestId,
            ]);
            $this->emitSse('done', ['requestId' => $this->requestId]);
        } catch (Throwable $e) {
            $errorMessage = $e->getMessage();
            $code = ErrorMapper::codeFor($e);
            Logger::exception($e, 'ragStreamFallback', $this->errorContext($params) + ['code' => $code->value]);
            $this->emitSse('error', [
                'code' => $code->value,
                'message' => ErrorMapper::translatedMessage($e) . " (Reference: {$this->requestId})",
                'requestId' => $this->requestId,
            ]);
        }

        $this->recordHistory('aiAnswer', $params, count($formattedSources), $errorMessage);
    }

    private function emitSse(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
        @flush();
    }

    /** Format pre-fetched Entry objects with parallel-array metadata (AI Answer flow). */
    private function formatElementResults(array $elements, array $metadataList, string $type): array
    {
        $formatted = [];
        foreach ($elements as $index => $element) {
            $result = SearchResultFormatter::format($element, $metadataList[$index] ?? [], $type);
            if ($result !== null) {
                $formatted[] = $result;
            }
        }
        return $formatted;
    }

    /** Format vector-query rows (`{element, content, ...}`). Generates excerpt from chunk content. */
    private function formatSearchResults(array $results, string $type): array
    {
        $formatted = [];
        foreach ($results as $result) {
            $metadata = $result + [
                'excerpt' => SearchResultFormatter::getExcerptFromContent(
                    $result['content'] ?? '',
                    $result['element']?->title
                ),
            ];
            $item = SearchResultFormatter::format($result['element'], $metadata, $type);
            if ($item !== null) {
                $formatted[] = $item;
            }
        }
        return $formatted;
    }

    private function logRequest(string $action, array $params): void
    {
        Logger::info('search request', [
            'requestId' => $this->requestId,
            'action' => $action,
            'q' => mb_substr($params['query'], 0, 100),
            'limit' => $params['limit'],
            'siteId' => $params['siteId'],
        ]);
    }

    private function successResponse(string $action, array $body): Response
    {
        $elapsedMs = (int)round((microtime(true) - $this->startTime) * 1000);
        $resultCount = $body['count'] ?? $body['semanticCount'] ?? null;

        Logger::timing("{$action} response", $elapsedMs, [
            'requestId' => $this->requestId,
            'results' => $resultCount,
        ]);

        return $this->asJson(['success' => true, 'requestId' => $this->requestId] + $body);
    }

    private function withRequestId(array $payload): array
    {
        return ['requestId' => $this->requestId] + $payload;
    }

    /**
     * Persist a search to history. Failures are swallowed so they never break the response.
     * Pulls token usage from UsageTracker (populated during the search).
     */
    private function recordHistory(string $type, array $params, int $resultsCount, ?string $errorMessage = null): void
    {
        try {
            $usage = UsageTracker::snapshot();

            SmartSearch::getInstance()->historyService->record(new SearchHistoryEntry(
                requestId: $this->requestId,
                type: $type,
                query: (string)($params['query'] ?? ''),
                siteId: $params['siteId'] ?? null,
                durationMs: (int)round((microtime(true) - $this->startTime) * 1000),
                resultsCount: $resultsCount,
                embeddingTokens: $usage['embeddingTokens'],
                aiAnswerInputTokens: $usage['aiAnswerInputTokens'],
                aiAnswerOutputTokens: $usage['aiAnswerOutputTokens'],
                embeddingCached: $usage['embeddingCached'],
                embeddingModel: $usage['embeddingModel'],
                aiAnswerModel: $usage['aiAnswerModel'],
                errorMessage: $errorMessage,
            ));
        } catch (Throwable $e) {
            Logger::exception($e, 'recordHistory', $this->errorContext($params));
        }
    }

    protected function errorContext(array $extra = []): array
    {
        return parent::errorContext([
            'q' => mb_substr($extra['query'] ?? '', 0, 100),
            'siteId' => $extra['siteId'] ?? null,
        ]);
    }
}

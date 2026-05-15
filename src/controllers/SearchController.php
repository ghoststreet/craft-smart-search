<?php

namespace ghoststreet\craftaisearch\controllers;

use Craft;
use craft\elements\Entry;
use craft\web\Controller;
use ghoststreet\craftaisearch\AiSearch;
use ghoststreet\craftaisearch\helpers\ApiResponseHelper;
use ghoststreet\craftaisearch\helpers\Logger;
use ghoststreet\craftaisearch\helpers\RequestParameterExtractor;
use ghoststreet\craftaisearch\helpers\SearchResultFormatter;
use ghoststreet\craftaisearch\helpers\UsageTracker;
use Throwable;
use yii\web\Response;
use yii\web\UnauthorizedHttpException;

/**
 * Search controller
 */
class SearchController extends Controller
{
    public $defaultAction = 'semantic-search';
    protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_LIVE;

    /** Short hex id tagging every log line and JSON response for this request. */
    private string $requestId = '';

    /** Monotonic start time for total-request timing. */
    private float $startTime = 0.0;

    /**
     * Validate API token and stamp the request with a correlation id.
     * If no token is configured in settings, requests are allowed unauthenticated.
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requestId = bin2hex(random_bytes(4));
        $this->startTime = microtime(true);
        UsageTracker::reset();

        $token = AiSearch::getInstance()->getSettings()->getApiToken();

        if (empty($token)) {
            return true;
        }

        $request = Craft::$app->getRequest();
        $authorization = (string)$request->getHeaders()->get('Authorization');
        $presented = str_starts_with($authorization, 'Bearer ')
            ? substr($authorization, 7)
            : (string)$request->getQueryParam('token');

        if ($presented !== '' && hash_equals($token, $presented)) {
            return true;
        }

        Logger::warning('auth failed', [
            'requestId' => $this->requestId,
            'action' => $action->id,
            'ip' => $request->getUserIP(),
        ]);

        throw new UnauthorizedHttpException('Invalid or missing API token.');
    }

    /**
     * Craft default search API endpoint
     */
    public function actionCraftSearch(): Response
    {
        $params = RequestParameterExtractor::extractSearchParams();

        if ($params['validationError'] !== null) {
            return $this->asJson($this->withRequestId($params['validationError']))->setStatusCode(400);
        }

        $this->logRequest('craftSearch', $params);

        try {
            $searchQuery = Entry::find();

            if ($params['siteId'] !== null) {
                $searchQuery->siteId($params['siteId']);
            }

            $searchQuery->status(Entry::STATUS_ENABLED)
                ->search($params['query'])
                ->limit($params['limit']);

            $entries = $searchQuery->all();

            $formattedResults = $this->formatElementResults($entries, [], SearchResultFormatter::TYPE_CRAFT);

            return $this->successResponse('craftSearch', [
                'query' => $params['query'],
                'results' => $formattedResults,
                'count' => count($formattedResults),
            ]);
        } catch (Throwable $e) {
            return ApiResponseHelper::jsonError($this, $e, 'craftSearch', $this->errorContext($params));
        }
    }

    /**
     * Hybrid (vector + BM25) search API endpoint.
     */
    public function actionSemanticSearch(): Response
    {
        $params = RequestParameterExtractor::extractSearchParams();

        if ($params['validationError'] !== null) {
            return $this->asJson($this->withRequestId($params['validationError']))->setStatusCode(400);
        }

        $this->logRequest('semanticSearch', $params);

        try {
            $results = AiSearch::getInstance()->searchService->search(
                $params['query'],
                $params['limit'],
                $params['siteId']
            );

            $formattedResults = $this->formatSearchResults($results, SearchResultFormatter::TYPE_HYBRID);

            $this->recordHistory('semantic', $params, [
                'resultsCount' => count($formattedResults),
                'results' => $formattedResults,
            ]);

            return $this->successResponse('semanticSearch', [
                'query' => $params['query'],
                'semanticResults' => $formattedResults,
                'semanticCount' => count($formattedResults),
            ]);
        } catch (Throwable $e) {
            $this->recordHistory('semantic', $params, [
                'resultsCount' => 0,
                'results' => null,
                'errorMessage' => $e->getMessage(),
            ]);
            return ApiResponseHelper::jsonError($this, $e, 'semanticSearch', $this->errorContext($params));
        }
    }

    /**
     * RAG search API endpoint with AI summary
     * Uses hybrid search + OpenAI for intelligent responses
     */
    public function actionRagSearch(): Response
    {
        $params = RequestParameterExtractor::extractSearchParams(20);

        if ($params['validationError'] !== null) {
            return $this->asJson($this->withRequestId($params['validationError']))->setStatusCode(400);
        }

        $this->logRequest('ragSearch', $params);

        try {
            $response = AiSearch::getInstance()->ragSearchService->search(
                $params['query'],
                $params['limit'],
                $params['siteId']
            );

            $formattedSources = $this->formatElementResults(
                array_column($response['sources'], 'element'),
                $response['sources'],
                SearchResultFormatter::TYPE_RAG
            );

            $this->recordHistory('rag', $params, [
                'resultsCount' => count($formattedSources),
                'results' => $formattedSources,
                'summary' => $response['summary'] ?? null,
                'confidence' => $response['confidence'] ?? null,
            ]);

            return $this->successResponse('ragSearch', [
                'query' => $params['query'],
                'summary' => $response['summary'] ?? null,
                'sources' => $formattedSources,
                'count' => count($formattedSources),
                'confidence' => $response['confidence'] ?? null,
            ]);
        } catch (Throwable $e) {
            $this->recordHistory('rag', $params, [
                'resultsCount' => 0,
                'results' => null,
                'errorMessage' => $e->getMessage(),
            ]);
            return ApiResponseHelper::jsonError($this, $e, 'ragSearch', $this->errorContext($params));
        }
    }

    /**
     * Streaming RAG endpoint. Emits Server-Sent Events:
     *   event: sources  data: {sources: [...]}
     *   event: token    data: {t: "..."}
     *   event: done     data: {}
     *   event: error    data: {message: "..."}
     */
    public function actionRagStream(): Response
    {
        $params = RequestParameterExtractor::extractSearchParams(20);

        // Release the session lock so the long-running stream doesn't block other
        // requests from this browser (Craft would otherwise hold the lock for the
        // entire streaming response).
        Craft::$app->getSession()->close();

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->content = '';

        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @ob_implicit_flush(true);

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');

        // Tell the client we're alive immediately so EventSource fires `open`
        // before any token arrives (and any proxy buffering is exposed early).
        echo ": connected\n\n";
        @flush();

        if ($params['validationError'] !== null) {
            $this->emitSse('error', $params['validationError']);
            Craft::$app->end();
            return $response;
        }

        $this->logRequest('ragStream', $params);

        $formattedSources = [];
        $summaryBuffer = '';
        $errorMessage = null;

        try {
            $generator = AiSearch::getInstance()->ragSearchService->searchStream(
                $params['query'],
                $params['limit'],
                $params['siteId']
            );

            foreach ($generator as $event) {
                switch ($event['type']) {
                    case 'sources':
                        $formattedSources = $this->formatElementResults(
                            array_column($event['sources'], 'element'),
                            $event['sources'],
                            SearchResultFormatter::TYPE_RAG
                        );
                        $this->emitSse('sources', ['sources' => $formattedSources, 'requestId' => $this->requestId]);
                        break;
                    case 'token':
                        $summaryBuffer .= $event['text'];
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
        } catch (Throwable $e) {
            $errorMessage = $e->getMessage();
            Logger::exception($e, 'ragStream', $this->errorContext($params));
            $this->emitSse('error', ['message' => $errorMessage, 'requestId' => $this->requestId]);
        }

        $this->recordHistory('rag', $params, [
            'resultsCount' => count($formattedSources),
            'results' => $formattedSources,
            'summary' => $summaryBuffer !== '' ? $summaryBuffer : null,
            'errorMessage' => $errorMessage,
        ]);

        Craft::$app->end();
        return $response;
    }

    private function emitSse(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
        @flush();
    }

    /**
     * Format a list of elements with optional metadata.
     */
    private function formatElementResults(array $elements, array $metadataList, string $type): array
    {
        $formatted = [];

        foreach ($elements as $index => $element) {
            $metadata = $metadataList[$index] ?? [];
            $result = SearchResultFormatter::format($element, $metadata, $type);

            if ($result !== null) {
                $formatted[] = $result;
            }
        }

        return $formatted;
    }

    /**
     * Format search results with excerpt generation.
     */
    private function formatSearchResults(array $results, string $type): array
    {
        $formatted = [];

        foreach ($results as $result) {
            $metadata = array_merge($result, [
                'excerpt' => SearchResultFormatter::getExcerptFromContent(
                    $result['content'] ?? '',
                    $result['element']?->title
                ),
            ]);

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
     *
     * $extra keys: resultsCount, results, summary?, confidence?, errorMessage?
     */
    private function recordHistory(string $type, array $params, array $extra): void
    {
        try {
            $usage = UsageTracker::snapshot();
            $user = Craft::$app->getUser()->getIdentity();

            AiSearch::getInstance()->historyService->record(array_merge([
                'requestId' => $this->requestId,
                'type' => $type,
                'query' => $params['query'] ?? '',
                'userId' => $user?->id,
                'siteId' => $params['siteId'] ?? null,
                'durationMs' => (int)round((microtime(true) - $this->startTime) * 1000),
                'embeddingTokens' => $usage['embeddingTokens'],
                'ragInputTokens' => $usage['ragInputTokens'],
                'ragOutputTokens' => $usage['ragOutputTokens'],
                'embeddingCached' => $usage['embeddingCached'],
                'embeddingModel' => $usage['embeddingModel'],
                'ragModel' => $usage['ragModel'],
            ], $extra));
        } catch (Throwable $e) {
            Logger::warning('recordHistory failed: ' . $e->getMessage());
        }
    }

    private function errorContext(array $params): array
    {
        return [
            'requestId' => $this->requestId,
            'q' => mb_substr($params['query'] ?? '', 0, 100),
            'siteId' => $params['siteId'] ?? null,
        ];
    }
}

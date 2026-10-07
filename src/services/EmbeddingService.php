<?php

namespace ghoststreet\craftsmartsearch\services;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\db\AssetQuery;
use craft\elements\db\CategoryQuery;
use craft\elements\db\ElementQuery;
use craft\elements\db\EntryQuery;
use craft\elements\db\TagQuery;
use craft\elements\Entry;
use craft\fields\Link;
use craft\fields\Time;
use craft\htmlfield\HtmlField;
use CurlHandle;
use CurlMultiHandle;
use DateTime;
use ghoststreet\craftsmartsearch\events\IndexBoostsEvent;
use ghoststreet\craftsmartsearch\events\IndexFieldTextEvent;
use ghoststreet\craftsmartsearch\exceptions\EmbeddingException;
use ghoststreet\craftsmartsearch\helpers\CacheTag;
use ghoststreet\craftsmartsearch\helpers\FieldPrefix;
use ghoststreet\craftsmartsearch\helpers\Logger;
use ghoststreet\craftsmartsearch\helpers\TextValidator;
use ghoststreet\craftsmartsearch\helpers\TokenEstimator;
use ghoststreet\craftsmartsearch\helpers\UsageTracker;
use ghoststreet\craftsmartsearch\providers\AiProvider;
use ghoststreet\craftsmartsearch\SmartSearch;
use ReflectionClass;
use RuntimeException;
use Throwable;
use yii\base\Component;

/**
 * Turns an entry into embeddable text, and text into vectors.
 *
 * Extracts text from all entry field types (including Matrix blocks), splits long
 * content into overlapping chunks, and generates embeddings via the provider's API with
 * two-level caching (request + persistent). Storage is the engine's, so what gets
 * indexed and where it lands stay separable.
 */
class EmbeddingService extends Component
{
    public const EVENT_INDEX_FIELD_TEXT = 'indexFieldText';

    public const EVENT_INDEX_BOOSTS = 'indexBoosts';

    private const CONNECT_TIMEOUT_MS = 3000;

    /** Bounds the whole call. No embedding is ever legitimately slower than this. */
    private const REQUEST_TIMEOUT_MS = 15000;

    /** Request-level cache, so one text is embedded at most once per request. */
    private static array $requestEmbeddingCache = [];

    /**
     * Generate a vector embedding for the given text via the provider's API.
     *
     * Results are cached at both request-level (in-memory) and persistent-level (Craft cache)
     * to avoid duplicate API calls within the same request or across requests.
     *
     * @param string $text The text to embed
     * @return array The embedding vector as an array of floats
     * @throws EmbeddingException If the text is empty or the API call fails
     */
    public function generateEmbedding(string $text): array
    {
        return ($this->dispatchEmbedding($text))();
    }

    /**
     * Write the embeddings request now and return a callable that reads the reply later.
     *
     * The embedding call is the longest wait in a search. Getting the request onto the
     * wire first lets the corrector lookup and the rest of the database work happen while
     * the reply is in flight.
     *
     * This bypasses the openai-php SDK for this one endpoint because the SDK is strictly
     * synchronous: its transporter calls sendRequest() and Guzzle's async path cannot be
     * stopped at "request written". The surface being taken over is one JSON POST with three
     * headers, of which only data[0].embedding and usage.prompt_tokens are read. Every other
     * API call still goes through the SDK.
     *
     * Returns an already-resolved callable on a cache hit, so a warm query never opens a socket.
     *
     * @return callable(): array
     * @throws EmbeddingException If the text is empty or the API call fails
     */
    public function dispatchEmbedding(string $text): callable
    {
        if (TextValidator::isEmpty($text)) {
            throw EmbeddingException::emptyText();
        }

        $settings = SmartSearch::getInstance()->getSettings();
        $provider = $settings->provider();
        $model = $settings->embeddingModel;
        $dimensions = $settings->dimensions;

        $normalizedText = TextValidator::sanitizeEmbeddingInput($text);
        if (TextValidator::isEmpty($normalizedText)) {
            throw EmbeddingException::emptyText();
        }

        $requestCacheKey = md5($normalizedText . '_' . $model . '_' . $dimensions);
        $persistentCacheKey = 'smart_search_embedding_' . $requestCacheKey;

        if (isset(self::$requestEmbeddingCache[$requestCacheKey])) {
            Logger::debug('Embedding cache hit (request-level)', [
                'textPreview' => substr($normalizedText, 0, 50) . '...',
            ]);
            UsageTracker::markEmbeddingCached($model);
            $hit = self::$requestEmbeddingCache[$requestCacheKey];

            return static fn(): array => $hit;
        }

        $cache = Craft::$app->getCache();
        $cachedEmbedding = $cache->get($persistentCacheKey);

        if (is_array($cachedEmbedding)) {
            self::$requestEmbeddingCache[$requestCacheKey] = $cachedEmbedding;
            UsageTracker::markEmbeddingCached($model);

            return static fn(): array => $cachedEmbedding;
        }

        $handles = $this->sendEmbeddingRequest(
            (string)$settings->getProviderApiKey(),
            ['model' => $model, 'input' => $normalizedText, 'dimensions' => $dimensions],
            $provider,
        );

        return fn(): array => $this->collectEmbedding($handles, $provider, $model, $requestCacheKey, $persistentCacheKey);
    }

    /**
     * Open the request and pump it only until the bytes are written. Past that point the
     * reply lands in the kernel socket buffer without PHP, which is what makes the caller's
     * database work free.
     *
     * @param array<string, mixed> $params
     * @return array{0: CurlMultiHandle, 1: CurlHandle}
     */
    private function sendEmbeddingRequest(string $apiKey, array $params, AiProvider $provider): array
    {
        $ch = curl_init('https://' . $provider->baseUri() . '/embeddings');
        $mh = curl_multi_init();

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => $this->curlHeaders($apiKey, $provider),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => 'gzip',
            CURLOPT_CONNECTTIMEOUT_MS => self::CONNECT_TIMEOUT_MS,
            CURLOPT_TIMEOUT_MS => self::REQUEST_TIMEOUT_MS,
        ]);

        curl_multi_add_handle($mh, $ch);

        $active = null;
        do {
            curl_multi_exec($mh, $active);
            if (curl_getinfo($ch, CURLINFO_PRETRANSFER_TIME) > 0 || !$active) {
                break;
            }
            curl_multi_select($mh, 0.05);
        } while (true);

        return [$mh, $ch];
    }

    /**
     * @return list<string>
     */
    private function curlHeaders(string $apiKey, AiProvider $provider): array
    {
        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        foreach ($provider->headers() as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        return $headers;
    }

    /**
     * Drain the dispatched request and turn it into a vector, recording usage and caching.
     *
     * @param array{0: CurlMultiHandle, 1: CurlHandle} $handles
     * @throws EmbeddingException
     */
    private function collectEmbedding(array $handles, AiProvider $provider, string $model, string $requestCacheKey, string $persistentCacheKey): array
    {
        [$mh, $ch] = $handles;

        try {
            $active = null;
            do {
                curl_multi_exec($mh, $active);
                if ($active) {
                    curl_multi_select($mh, 0.5);
                }
            } while ($active);

            // A multi transfer's error code comes from here: curl_errno() stays 0 until it is read.
            $info = curl_multi_info_read($mh);

            $body = curl_multi_getcontent($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $errno = $info === false ? 0 : $info['result'];
            $error = curl_error($ch);
            $elapsedSeconds = max(1, (int)round(curl_getinfo($ch, CURLINFO_TOTAL_TIME)));
        } finally {
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            curl_multi_close($mh);
        }

        if ($errno !== 0) {
            $publicMessage = $errno === CURLE_OPERATION_TIMEDOUT
                ? self::failureMessage('The embedding request to {provider} for {model} timed out after {seconds} seconds.', $provider, $model, ['seconds' => $elapsedSeconds])
                : self::failureMessage('The embedding request to {provider} for {model} could not connect.', $provider, $model);
            $e = EmbeddingException::apiError($publicMessage, new RuntimeException($error));
            Logger::exception($e, 'dispatchEmbedding', ['model' => $model, 'curlErrno' => $errno]);
            throw $e;
        }

        $decoded = json_decode((string)$body, true);

        if ($status !== 200 || !is_array($decoded)) {
            $e = $this->mapHttpError($status, is_array($decoded) ? $decoded : [], $provider, $model);
            Logger::exception($e, 'dispatchEmbedding', ['model' => $model, 'status' => $status]);
            throw $e;
        }

        $embedding = $decoded['data'][0]['embedding'] ?? null;
        if (!is_array($embedding)) {
            $e = EmbeddingException::apiError(
                self::failureMessage('The embedding request to {provider} for {model} returned no embedding.', $provider, $model),
                new RuntimeException('malformed response'),
            );
            Logger::exception($e, 'dispatchEmbedding', ['model' => $model]);
            throw $e;
        }

        $promptTokens = (int)($decoded['usage']['prompt_tokens'] ?? $decoded['usage']['total_tokens'] ?? 0);

        $cost = isset($decoded['usage']['cost']) ? (float)$decoded['usage']['cost'] : null;

        UsageTracker::addEmbedding($model, $promptTokens, $cost);

        return $this->remember($embedding, $requestCacheKey, $persistentCacheKey);
    }

    /** Keep a fresh embedding for the rest of the request and, per settings, across requests. */
    private function remember(array $embedding, string $requestCacheKey, string $persistentCacheKey): array
    {
        self::$requestEmbeddingCache[$requestCacheKey] = $embedding;

        $ttlDays = SmartSearch::getInstance()->getSettings()->embeddingCacheTtlDays;
        if ($ttlDays > 0) {
            Craft::$app->getCache()->set($persistentCacheKey, $embedding, $ttlDays * 86400, CacheTag::dependency());
        }

        return $embedding;
    }

    /**
     * Map an embeddings HTTP error to the EmbeddingException subtype the API's error codes
     * are built on.
     *
     * @param array<string, mixed> $decoded
     */
    private function mapHttpError(int $status, array $decoded, AiProvider $provider, string $model): EmbeddingException
    {
        $code = (string)($decoded['error']['code'] ?? '');
        $previous = new RuntimeException((string)($decoded['error']['message'] ?? 'HTTP ' . $status));

        if ($status === 429) {
            return EmbeddingException::rateLimited(self::failureMessage('{provider} rate-limited the embedding request for {model}. Please retry shortly.', $provider, $model), $previous);
        }
        if ($status === 401 || $code === 'invalid_api_key') {
            return EmbeddingException::invalidApiKey(self::failureMessage('{provider} rejected the API key for the embedding request.', $provider, $model), $previous);
        }
        if ($code === 'insufficient_quota') {
            return EmbeddingException::quotaExceeded(self::failureMessage('{provider} refused the embedding request for {model}: the account is out of quota.', $provider, $model), $previous);
        }

        return EmbeddingException::apiError(
            self::failureMessage('The embedding request to {provider} for {model} failed with HTTP {status}.', $provider, $model, ['status' => $status]),
            $previous,
        );
    }

    /** A failure sentence in the plugin's own words, naming the provider and model. */
    private static function failureMessage(string $message, AiProvider $provider, string $model, array $params = []): string
    {
        return Craft::t('smart-search', $message, ['provider' => $provider::label(), 'model' => $model] + $params);
    }

    /**
     * Extract all indexable text from an element by iterating its field layout.
     *
     * Prepends the element title, then concatenates text from all custom fields
     * separated by double newlines.
     */
    public function extractTextFromElement(ElementInterface $element): string
    {
        $textParts = [];

        if ($element->title) {
            $textParts[] = FieldPrefix::scrub($element->title);
        }

        $fieldTexts = $this->extractFieldsFromLayout($element);
        $textParts = array_merge($textParts, $fieldTexts);

        return implode("\n\n", array_filter($textParts));
    }

    /**
     * Extract indexable text from a field value using type-based dispatch.
     *
     * Handles strings, dates, element queries (entries/relations), arrays (table fields),
     * iterables (Matrix blocks), and objects with getPlainText()/__toString().
     * Returns empty string for non-textual types (assets, categories, tags, booleans).
     */
    private function extractTextFromFieldValue(FieldInterface $field, mixed $fieldValue): string
    {
        if ($fieldValue instanceof DateTime) {
            return $this->formatDateTimeField($field, $fieldValue);
        }

        if ($fieldValue instanceof ElementQuery) {
            if ($fieldValue instanceof EntryQuery) {
                $entries = $fieldValue->all();
                if (!empty($entries) && self::isNestedEntry($entries[0])) {
                    return $this->extractTextFromIterable($entries);
                }
            }

            if ($fieldValue instanceof AssetQuery ||
                $fieldValue instanceof CategoryQuery ||
                $fieldValue instanceof TagQuery) {
                return '';
            }

            $titles = [];
            foreach ($fieldValue->all() as $relatedElement) {
                if (isset($relatedElement->title) && !TextValidator::isEmpty($relatedElement->title)) {
                    $titles[] = $relatedElement->title;
                }
            }
            return implode(', ', $titles);
        }

        if (is_string($fieldValue)) {
            return strip_tags($fieldValue);
        }

        if (is_array($fieldValue)) {
            return $this->extractTextFromArray($fieldValue);
        }

        if (is_object($fieldValue) && method_exists($fieldValue, 'getPlainText')) {
            return $fieldValue->getPlainText();
        }

        if (is_object($fieldValue) && method_exists($fieldValue, '__toString')) {
            return strip_tags((string)$fieldValue);
        }

        if (is_iterable($fieldValue)) {
            return $this->extractTextFromIterable($fieldValue);
        }

        return '';
    }

    /**
     * Format a DateTime field value.
     *
     * Craft's Time field stores values as full DateTime objects with today's date as
     * the date portion; formatting as "F j, Y" would index today's date instead of
     * the actual time. Pick the format from the field type.
     */
    private function formatDateTimeField(FieldInterface $field, DateTime $value): string
    {
        if ($field instanceof Time) {
            return $value->format('H:i');
        }

        return $value->format('F j, Y');
    }

    /**
     * Extract text from array values (Table fields, nested arrays).
     *
     * Recursively processes string, array, and numeric values,
     * joining all extracted text with spaces.
     */
    private function extractTextFromArray(array $data): string
    {
        $textParts = [];

        foreach ($data as $item) {
            if (is_string($item)) {
                $text = strip_tags(trim($item));
                if (!TextValidator::isEmpty($text)) {
                    $textParts[] = $text;
                }
            } elseif (is_array($item)) {
                $nested = $this->extractTextFromArray($item);
                if (!TextValidator::isEmpty($nested)) {
                    $textParts[] = $nested;
                }
            } elseif (is_numeric($item)) {
                $textParts[] = (string)$item;
            }
        }

        return implode(' ', $textParts);
    }

    /** A Matrix block: in Craft 5 a nested entry, told apart by having an owner. */
    private static function isNestedEntry(mixed $item): bool
    {
        return $item instanceof Entry && $item->getOwnerId() !== null;
    }

    /**
     * CKEditor / Redactor fields. Their prose carries its own context, so it is
     * embedded without a "Label: " prefix. class_exists() keeps craftcms/html-field
     * a soft dependency.
     */
    private function isRichTextField(FieldInterface $field): bool
    {
        return class_exists(HtmlField::class) && $field instanceof HtmlField;
    }

    /**
     * Extract text from iterable collections (Matrix blocks).
     *
     * Detects block elements by checking for nested entries (Matrix in Craft 5) and
     * extracts text from their field layouts.
     */
    private function extractTextFromIterable(mixed $iterable): string
    {
        $textParts = [];

        foreach ($iterable as $item) {
            if (self::isNestedEntry($item)) {
                $blockText = implode(' ', $this->extractFieldsFromLayout($item));
                if (!TextValidator::isEmpty($blockText)) {
                    $textParts[] = $blockText;
                }
            } elseif (is_string($item)) {
                $text = strip_tags(trim($item));
                if (!TextValidator::isEmpty($text)) {
                    $textParts[] = $text;
                }
            }
        }

        return implode(' ', $textParts);
    }

    /**
     * Shared field extraction logic used by both top-level elements and nested blocks.
     *
     * Iterates the custom fields in the element's field layout, extracts text from each
     * searchable, non-null field value, and returns an array of non-empty strings.
     * Searchable is Craft's own "Use this field's values as search keywords" setting,
     * so a field left out of Craft's search is left out of this index too.
     *
     * @return string[] Extracted text parts from each field
     */
    private function extractFieldsFromLayout(ElementInterface $element): array
    {
        $textParts = [];

        foreach ($this->inspectFieldsFromLayout($element) as $row) {
            if ($row['indexed']) {
                $textParts[] = $row['extractedText'];
            }
        }

        return $textParts;
    }

    /**
     * Per-field breakdown of how the indexer sees an element's field layout.
     *
     * Used by the Index inspection view to verify which fields contribute text, which are skipped,
     * and why. Mirrors extractFieldsFromLayout() so the report cannot drift from real
     * indexing behavior.
     *
     * @return array<int, array{handle: string, type: string, indexed: bool, reason: string, extractedText: string, prefix?: string}>
     */
    public function inspectFieldsFromLayout(ElementInterface $element): array
    {
        $rows = [];

        $fieldLayout = $element->getFieldLayout();
        if ($fieldLayout === null) {
            return [];
        }

        foreach ($fieldLayout->getCustomFieldElements() as $layoutElement) {
            $field = $layoutElement->getField();
            $row = [
                'handle' => $field->handle,
                'type' => (new ReflectionClass($field))->getShortName(),
                'indexed' => false,
                'reason' => '',
                'extractedText' => '',
                'blocks' => [],
            ];

            if (!$field->searchable) {
                $rows[] = ['reason' => 'Not searchable'] + $row;
                continue;
            }

            if ($field instanceof Link) {
                $rows[] = ['reason' => 'skipped'] + $row;
                continue;
            }

            $fieldValue = $element->getFieldValue($field->handle);
            if ($fieldValue === null) {
                $rows[] = ['reason' => 'null value'] + $row;
                continue;
            }

            $extracted = $this->extractTextFromFieldValue($field, $fieldValue);
            $extracted = $this->applyIndexFieldTextListeners($element, $field, $fieldValue, $extracted);
            $blocks = $this->inspectBlocksFromFieldValue($fieldValue);
            if ($blocks === []) {
                $extracted = FieldPrefix::scrub($extracted);
            }

            if (TextValidator::isEmpty($extracted)) {
                $rows[] = ['reason' => 'no extractable text', 'blocks' => $blocks] + $row;
                continue;
            }

            $prefix = $blocks === [] && !$this->isRichTextField($field) ? FieldPrefix::wrap($layoutElement->label()) : '';

            $rows[] = [
                'indexed' => true,
                'extractedText' => $prefix . $extracted,
                'prefix' => $prefix,
                'blocks' => $blocks,
            ] + $row;
        }

        return $rows;
    }

    /**
     * Let EVENT_INDEX_FIELD_TEXT listeners rewrite the text a field contributes
     * to the index. A throwing listener is logged and the original text kept.
     */
    private function applyIndexFieldTextListeners(ElementInterface $element, FieldInterface $field, mixed $value, string $text): string
    {
        if (!$this->hasEventHandlers(self::EVENT_INDEX_FIELD_TEXT)) {
            return $text;
        }

        try {
            $event = new IndexFieldTextEvent([
                'element' => $element,
                'field' => $field,
                'value' => $value,
                'text' => $text,
            ]);
            $this->trigger(self::EVENT_INDEX_FIELD_TEXT, $event);
            return $event->text;
        } catch (Throwable $e) {
            Logger::exception($e, 'indexFieldText event', ['elementId' => $element->id, 'field' => $field->handle]);
            return $text;
        }
    }

    /**
     * Let EVENT_INDEX_BOOSTS listeners attach weighted boost rules to an entry.
     * A throwing listener is logged and no rules are attached.
     *
     * The rules come from an untrusted listener, so each one is read defensively here,
     * once: phrases are trimmed, empty ones dropped, and a rule with no phrases or a
     * non-positive weight is dropped whole.
     *
     * @return list<array{terms: list<string>, weight: float}>
     */
    public function collectBoostRules(ElementInterface $element): array
    {
        if (!$this->hasEventHandlers(self::EVENT_INDEX_BOOSTS)) {
            return [];
        }

        try {
            $event = new IndexBoostsEvent(['element' => $element]);
            $this->trigger(self::EVENT_INDEX_BOOSTS, $event);
        } catch (Throwable $e) {
            Logger::exception($e, 'indexBoosts event', ['elementId' => $element->id]);
            return [];
        }

        /** @var array<array-key, mixed> $untrusted the documented shape is a request, not a guarantee */
        $untrusted = $event->rules;

        $rules = [];
        foreach ($untrusted as $rule) {
            $terms = is_array($rule['terms'] ?? null) ? $rule['terms'] : [];
            $terms = array_values(array_filter(
                array_map(static fn(mixed $t): string => trim((string)$t), $terms),
                static fn(string $t): bool => $t !== '',
            ));
            $weight = (float)($rule['weight'] ?? 0);

            if ($terms !== [] && $weight > 0) {
                $rules[] = ['terms' => $terms, 'weight' => $weight];
            }
        }

        return $rules;
    }

    /**
     * If the field value is an iterable of Matrix blocks, return a per-block breakdown with
     * each block's own per-field inspection rows. Otherwise empty.
     *
     * @return array<int, array{label: string, blockTypeHandle: string, id: ?int, fields: array}>
     */
    private function inspectBlocksFromFieldValue(mixed $fieldValue): array
    {
        if (!is_iterable($fieldValue)) {
            return [];
        }

        $blocks = [];
        $index = 0;

        foreach ($fieldValue as $item) {
            if (!self::isNestedEntry($item)) {
                continue;
            }

            $typeHandle = $item->getType()->handle;
            $index++;

            $blocks[] = [
                'label' => $typeHandle . ' #' . $index,
                'blockTypeHandle' => $typeHandle,
                'id' => $item->id,
                'fields' => $this->inspectFieldsFromLayout($item),
            ];
        }

        return $blocks;
    }

    /**
     * Split text into chunks sized for embedding generation.
     *
     * Strategy: splits by paragraphs first, falling back to sentence-level splitting
     * when a single paragraph exceeds maxChunkTokens. Adjacent chunks share an overlap
     * region (controlled by overlapTokens) so that context spanning a chunk boundary
     * is still captured by at least one embedding. Text shorter than chunkThresholdTokens
     * is returned as a single chunk.
     *
     * @return string[] One or more text chunks
     */
    public function chunkText(string $text): array
    {
        $settings = SmartSearch::getInstance()->getSettings();

        $estimatedTokens = TokenEstimator::estimateTokens($text);

        if ($estimatedTokens < $settings->chunkThresholdTokens) {
            return [$text];
        }

        $paragraphs = preg_split('/\n\s*\n/', $text, -1, PREG_SPLIT_NO_EMPTY);

        $chunks = [];
        $currentChunk = '';
        $currentTokens = 0;

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if (TextValidator::isEmpty($paragraph)) {
                continue;
            }

            $paragraphTokens = TokenEstimator::estimateTokens($paragraph);

            if ($paragraphTokens > $settings->maxChunkTokens) {
                if (!TextValidator::isEmpty($currentChunk)) {
                    $chunks[] = trim($currentChunk);
                    $currentChunk = '';
                    $currentTokens = 0;
                }
                $sentenceChunks = $this->splitBySentences($paragraph, $settings->targetChunkTokens, $settings->maxChunkTokens);
                $chunks = array_merge($chunks, $sentenceChunks);
                continue;
            }

            if ($currentTokens + $paragraphTokens > $settings->targetChunkTokens && $currentTokens >= $settings->minChunkTokens) {
                $chunks[] = trim($currentChunk);
                $overlap = $this->getOverlapText($currentChunk, $settings->overlapTokens);
                $currentChunk = ($overlap === '' ? '' : $overlap . "\n\n") . $paragraph;
                $currentTokens = TokenEstimator::estimateTokens($currentChunk);
            } else {
                $currentChunk .= (TextValidator::isEmpty($currentChunk) ? '' : "\n\n") . $paragraph;
                $currentTokens += $paragraphTokens;
            }
        }

        if (!TextValidator::isEmpty($currentChunk)) {
            $chunks[] = trim($currentChunk);
        }

        return $chunks;
    }

    /**
     * Split a paragraph into sentence-level chunks when it exceeds the max chunk size.
     *
     * @param string $text The paragraph text to split
     * @param int $targetChunkTokens Target token count per chunk
     * @param int $maxChunkTokens Hard upper limit; sentences longer than this are cut mid-sentence
     * @return string[] Sentence-level chunks
     */
    private function splitBySentences(string $text, int $targetChunkTokens, int $maxChunkTokens): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        $chunks = [];
        $currentChunk = '';
        $currentTokens = 0;

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if (TextValidator::isEmpty($sentence)) {
                continue;
            }

            $sentenceTokens = TokenEstimator::estimateTokens($sentence);

            if ($sentenceTokens > $maxChunkTokens) {
                if (!TextValidator::isEmpty($currentChunk)) {
                    $chunks[] = trim($currentChunk);
                    $currentChunk = '';
                    $currentTokens = 0;
                }
                foreach ($this->splitByLength($sentence, $maxChunkTokens) as $piece) {
                    $chunks[] = $piece;
                }
                continue;
            }

            if ($currentTokens + $sentenceTokens > $targetChunkTokens && !TextValidator::isEmpty($currentChunk)) {
                $chunks[] = trim($currentChunk);
                $currentChunk = $sentence;
                $currentTokens = $sentenceTokens;
            } else {
                $currentChunk .= (TextValidator::isEmpty($currentChunk) ? '' : ' ') . $sentence;
                $currentTokens += $sentenceTokens;
            }
        }

        if (!TextValidator::isEmpty($currentChunk)) {
            $chunks[] = trim($currentChunk);
        }

        return $chunks;
    }

    /**
     * Hard-cut a string into pieces no longer than $maxChunkTokens.
     * Used when a single sentence exceeds the maximum chunk size.
     *
     * @return string[]
     */
    private function splitByLength(string $text, int $maxChunkTokens): array
    {
        $pieces = array_map('trim', str_split($text, TokenEstimator::estimateChars($maxChunkTokens)));

        return array_values(array_filter($pieces, static fn(string $p): bool => $p !== ''));
    }

    /**
     * Extract the trailing overlap region from a chunk for context continuity.
     *
     * Takes the last N characters (estimated from overlapTokens) and trims to the
     * nearest word boundary to avoid splitting mid-word.
     */
    private function getOverlapText(string $text, int $overlapTokens): string
    {
        $targetChars = TokenEstimator::estimateChars($overlapTokens);

        if (strlen($text) <= $targetChars) {
            return '';
        }

        $overlap = substr($text, -$targetChars);
        $firstSpace = strpos($overlap, ' ');

        if ($firstSpace !== false) {
            $overlap = substr($overlap, $firstSpace + 1);
        }

        return trim($overlap);
    }
}

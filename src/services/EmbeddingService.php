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
use DateTime;
use ghoststreet\craftsmartsearch\SmartSearch;
use ghoststreet\craftsmartsearch\exceptions\DatabaseException;
use ghoststreet\craftsmartsearch\exceptions\EmbeddingException;
use ghoststreet\craftsmartsearch\exceptions\SearchException;
use ghoststreet\craftsmartsearch\helpers\ContentPatterns;
use ghoststreet\craftsmartsearch\helpers\Logger;
use ghoststreet\craftsmartsearch\helpers\TextValidator;
use ghoststreet\craftsmartsearch\helpers\TokenEstimator;
use ghoststreet\craftsmartsearch\models\Settings;
use ghoststreet\craftsmartsearch\helpers\UsageTracker;
use OpenAI\Client;
use OpenAI\Exceptions\ErrorException;
use PDOException;
use Pgvector\Vector;
use yii\base\Component;

/**
 * Embedding Service for generating and storing vector embeddings.
 *
 * Handles the full indexing pipeline: extracts text from all entry field types
 * (including Matrix, Super Table, and nested blocks), splits long content into
 * overlapping semantic chunks, generates embeddings via the OpenAI API with
 * two-level caching (request + persistent), and stores vectors in pgvector.
 */
class EmbeddingService extends Component
{
    /**
     * Request-level cache for query embeddings.
     * Prevents duplicate API calls when agent calls multiple semantic-based tools with the same query.
     */
    private static array $requestEmbeddingCache = [];

    /**
     * Get the shared OpenAI client instance from the factory.
     */
    private function getOpenAIClient(): Client
    {
        return SmartSearch::getInstance()->openAIClientFactory->getClient();
    }

    /**
     * Map OpenAI error responses to specific EmbeddingException subtypes
     * for rate limits, quota, and auth issues.
     */
    private function mapOpenAIException(ErrorException $e): EmbeddingException
    {
        $message = $e->getMessage();

        if (stripos($message, 'rate limit') !== false) {
            return EmbeddingException::rateLimited($e);
        }
        if (stripos($message, 'insufficient_quota') !== false) {
            return EmbeddingException::quotaExceeded($e);
        }
        if (stripos($message, 'invalid_api_key') !== false) {
            return EmbeddingException::invalidApiKey($e);
        }

        return EmbeddingException::apiError($message, $e);
    }

    /**
     * Generate a vector embedding for the given text via the OpenAI API.
     *
     * Results are cached at both request-level (in-memory) and persistent-level (Craft cache)
     * to avoid duplicate API calls within the same request or across requests.
     *
     * @param string $text The text to embed
     * @param bool $useCache Whether to use persistent cache lookup/storage
     * @param string|null $model Override the default embedding model
     * @return array The embedding vector as an array of floats
     * @throws EmbeddingException If the text is empty or the API call fails
     */
    public function generateEmbedding(string $text, bool $useCache = true, ?string $model = null): array
    {
        if (TextValidator::isEmpty($text)) {
            throw EmbeddingException::emptyText();
        }

        $settings = SmartSearch::getInstance()->getSettings();
        $model = $model ?? $settings->hybridEmbeddingModel;

        $normalizedText = TextValidator::sanitizeEmbeddingInput($text);
        if (TextValidator::isEmpty($normalizedText)) {
            throw EmbeddingException::emptyText();
        }
        $requestCacheKey = md5($normalizedText . '_' . $model);
        $persistentCacheKey = 'smart_search_embedding_' . $requestCacheKey;

        if (isset(self::$requestEmbeddingCache[$requestCacheKey])) {
            Logger::debug('Embedding cache hit (request-level)', [
                'textPreview' => substr($normalizedText, 0, 50) . '...',
            ]);
            UsageTracker::markEmbeddingCached($model);
            return self::$requestEmbeddingCache[$requestCacheKey];
        }

        $cache = $useCache ? Craft::$app->getCache() : null;

        if ($cache !== null) {
            $cachedEmbedding = $cache->get($persistentCacheKey);

            if ($cachedEmbedding !== false && is_array($cachedEmbedding)) {
                self::$requestEmbeddingCache[$requestCacheKey] = $cachedEmbedding;
                UsageTracker::markEmbeddingCached($model);
                return $cachedEmbedding;
            }
        }

        try {
            $client = $this->getOpenAIClient();

            $params = [
                'model' => $model,
                'input' => $normalizedText,
            ];

            $params['dimensions'] = Settings::VECTOR_DIMENSIONS;

            $response = $client->embeddings()->create($params);

            $embedding = $response->embeddings[0]->embedding;

            $promptTokens = (int)($response->usage->promptTokens ?? $response->usage->totalTokens ?? 0);
            UsageTracker::addEmbedding($model, $promptTokens);

            self::$requestEmbeddingCache[$requestCacheKey] = $embedding;

            if ($cache !== null) {
                $cache->set($persistentCacheKey, $embedding, $settings->embeddingCacheTtl);
            }

            return $embedding;
        } catch (ErrorException $e) {
            Logger::exception($e, 'generateEmbedding', ['model' => $model]);
            throw $this->mapOpenAIException($e);
        }
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
            $textParts[] = $element->title;
        }

        $fieldTexts = $this->extractFieldsFromLayout($element);
        $textParts = array_merge($textParts, $fieldTexts);

        return implode("\n\n", array_filter($textParts));
    }

    /**
     * Extract indexable text from a field value using type-based dispatch.
     *
     * Handles strings, dates, element queries (entries/relations), arrays (table fields),
     * iterables (Matrix/SuperTable blocks), and objects with getPlainText()/__toString().
     * Returns empty string for non-textual types (assets, categories, tags, booleans).
     */
    private function extractTextFromFieldValue(FieldInterface $field, mixed $fieldValue): string
    {
        if ($field instanceof Link) {
            return '';
        }

        if ($fieldValue instanceof DateTime) {
            return $this->formatDateTimeField($field, $fieldValue);
        }

        if ($fieldValue instanceof ElementQuery) {
            if ($fieldValue instanceof EntryQuery) {
                $entries = $fieldValue->all();
                if (!empty($entries) && $entries[0] instanceof Entry && $entries[0]->getOwnerId() !== null) {
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
                if (isset($relatedElement->title) && TextValidator::isNotEmpty($relatedElement->title)) {
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

        // getPlainText/__toString must run before is_iterable: CKEditor/Redactor
        // FieldData implements IteratorAggregate yielding chunk objects, not text.
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
     * the date portion — formatting as "F j, Y" would index today's date instead of
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
                if (TextValidator::isNotEmpty($text)) {
                    $textParts[] = $text;
                }
            } elseif (is_array($item)) {
                $nested = $this->extractTextFromArray($item);
                if (TextValidator::isNotEmpty($nested)) {
                    $textParts[] = $nested;
                }
            } elseif (is_numeric($item)) {
                $textParts[] = (string)$item;
            }
        }

        return implode(' ', $textParts);
    }

    /**
     * Extract text from iterable collections (Matrix blocks, Super Table rows).
     *
     * Detects block elements by checking for nested Entry (Matrix in Craft 5) or
     * SuperTableBlockElement instances and extracts text from their field layouts.
     */
    private function extractTextFromIterable(mixed $iterable): string
    {
        $textParts = [];

        foreach ($iterable as $item) {
            if (($item instanceof Entry && $item->getOwnerId() !== null) || $this->isSuperTableBlock($item)) {
                $blockText = $this->extractTextFromBlockElement($item);
                if (TextValidator::isNotEmpty($blockText)) {
                    $textParts[] = $blockText;
                }
            } elseif (is_string($item)) {
                $text = strip_tags(trim($item));
                if (TextValidator::isNotEmpty($text)) {
                    $textParts[] = $text;
                }
            }
        }

        return implode(' ', $textParts);
    }

    /**
     * Check if an object is a Super Table block element.
     *
     * Uses class_exists to avoid a hard dependency on the verbb/super-table package.
     */
    private function isSuperTableBlock(mixed $item): bool
    {
        return is_object($item)
            && class_exists('verbb\\supertable\\elements\\SuperTableBlockElement')
            && $item instanceof \verbb\supertable\elements\SuperTableBlockElement;
    }

    /**
     * Extract text from a block element (Matrix block or Super Table row)
     * by iterating its field layout.
     */
    private function extractTextFromBlockElement(ElementInterface $blockElement): string
    {
        return implode(' ', $this->extractFieldsFromLayout($blockElement));
    }

    /**
     * Shared field extraction logic used by both top-level elements and nested blocks.
     *
     * Iterates all custom fields in the element's field layout, extracts text
     * from each non-null field value, and returns an array of non-empty strings.
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
     * @return array<int, array{handle: string, type: string, searchable: bool, indexed: bool, reason: string, extractedText: string}>
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
            $searchable = (bool)($layoutElement->searchable ?? true);
            $type = (new \ReflectionClass($field))->getShortName();

            if (!$searchable) {
                $rows[] = [
                    'handle' => $field->handle,
                    'type' => $type,
                    'searchable' => false,
                    'indexed' => false,
                    'reason' => 'searchable=false',
                    'extractedText' => '',
                    'blocks' => [],
                ];
                continue;
            }

            if ($field instanceof Link) {
                $rows[] = [
                    'handle' => $field->handle,
                    'type' => $type,
                    'searchable' => true,
                    'indexed' => false,
                    'reason' => 'skipped',
                    'extractedText' => '',
                    'blocks' => [],
                ];
                continue;
            }

            $fieldValue = $element->getFieldValue($field->handle);
            if ($fieldValue === null) {
                $rows[] = [
                    'handle' => $field->handle,
                    'type' => $type,
                    'searchable' => true,
                    'indexed' => false,
                    'reason' => 'null value',
                    'extractedText' => '',
                    'blocks' => [],
                ];
                continue;
            }

            $extracted = $this->extractTextFromFieldValue($field, $fieldValue);
            $blocks = $this->inspectBlocksFromFieldValue($fieldValue);

            if (!TextValidator::isNotEmpty($extracted)) {
                $rows[] = [
                    'handle' => $field->handle,
                    'type' => $type,
                    'searchable' => true,
                    'indexed' => false,
                    'reason' => 'no extractable text',
                    'extractedText' => '',
                    'blocks' => $blocks,
                ];
                continue;
            }

            $rows[] = [
                'handle' => $field->handle,
                'type' => $type,
                'searchable' => true,
                'indexed' => true,
                'reason' => '',
                'extractedText' => $extracted,
                'blocks' => $blocks,
            ];
        }

        return $rows;
    }

    /**
     * If the field value is an iterable of block elements (Matrix entries / Super Table rows),
     * return a per-block breakdown with each block's own per-field inspection rows. Otherwise empty.
     *
     * @return array<int, array{label: string, blockTypeHandle: ?string, id: ?int, fields: array}>
     */
    private function inspectBlocksFromFieldValue(mixed $fieldValue): array
    {
        if (!is_iterable($fieldValue)) {
            return [];
        }

        $blocks = [];
        $index = 0;

        foreach ($fieldValue as $item) {
            $isMatrixBlock = $item instanceof Entry && $item->getOwnerId() !== null;
            $isSuperTable = $this->isSuperTableBlock($item);
            if (!$isMatrixBlock && !$isSuperTable) {
                continue;
            }

            $typeHandle = null;
            if ($isMatrixBlock) {
                $typeHandle = $item->getType()?->handle;
            } elseif (method_exists($item, 'getType')) {
                $typeHandle = $item->getType()?->handle ?? null;
            }

            $index++;
            $label = ($typeHandle ?? 'block') . ' #' . $index;

            $blocks[] = [
                'label' => $label,
                'blockTypeHandle' => $typeHandle,
                'id' => $item->id ?? null,
                'fields' => $this->inspectFieldsFromLayout($item),
            ];
        }

        return $blocks;
    }

    /**
     * Run the full extraction + chunking pipeline without writing to the DB.
     *
     * @return string[]
     */
    public function previewChunks(ElementInterface $element): array
    {
        $text = $this->extractTextFromElement($element);
        if (TextValidator::isEmpty($text)) {
            return [];
        }
        return $this->chunkText($text);
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
    private function chunkText(string $text): array
    {
        $settings = SmartSearch::getInstance()->getSettings();

        $estimatedTokens = TokenEstimator::estimateTokens($text);

        if ($estimatedTokens < $settings->chunkThresholdTokens) {
            return [$text];
        }

        $paragraphs = ContentPatterns::splitParagraphs($text);

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
                if (TextValidator::isNotEmpty($currentChunk)) {
                    $chunks[] = trim($currentChunk);
                    $currentChunk = '';
                    $currentTokens = 0;
                }
                $sentenceChunks = $this->splitBySentences($paragraph, $settings->targetChunkTokens);
                $chunks = array_merge($chunks, $sentenceChunks);
                continue;
            }

            if ($currentTokens + $paragraphTokens > $settings->targetChunkTokens && $currentTokens >= $settings->minChunkTokens) {
                $chunks[] = trim($currentChunk);
                $overlap = $this->getOverlapText($currentChunk, $settings->overlapTokens);
                $currentChunk = $overlap . "\n\n" . $paragraph;
                $currentTokens = $settings->overlapTokens + $paragraphTokens;
            } else {
                $currentChunk .= (TextValidator::isEmpty($currentChunk) ? '' : "\n\n") . $paragraph;
                $currentTokens += $paragraphTokens;
            }
        }

        if (TextValidator::isNotEmpty($currentChunk)) {
            $chunks[] = trim($currentChunk);
        }

        if (empty($chunks)) {
            return [$text];
        }

        return $chunks;
    }

    /**
     * Split a paragraph into sentence-level chunks when it exceeds the max chunk size.
     *
     * @param string $text The paragraph text to split
     * @param int $targetChunkTokens Target token count per chunk
     * @return string[] Sentence-level chunks
     */
    private function splitBySentences(string $text, int $targetChunkTokens): array
    {
        $sentences = ContentPatterns::splitSentences($text);

        $chunks = [];
        $currentChunk = '';
        $currentTokens = 0;

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if (TextValidator::isEmpty($sentence)) {
                continue;
            }

            $sentenceTokens = TokenEstimator::estimateTokens($sentence);

            if ($currentTokens + $sentenceTokens > $targetChunkTokens && TextValidator::isNotEmpty($currentChunk)) {
                $chunks[] = trim($currentChunk);
                $currentChunk = $sentence;
                $currentTokens = $sentenceTokens;
            } else {
                $currentChunk .= (TextValidator::isEmpty($currentChunk) ? '' : ' ') . $sentence;
                $currentTokens += $sentenceTokens;
            }
        }

        if (TextValidator::isNotEmpty($currentChunk)) {
            $chunks[] = trim($currentChunk);
        }

        return $chunks;
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

    /**
     * Index an element by extracting text, chunking if needed, and storing embeddings.
     *
     * Only processes Entry elements that have a URI. Deletes any existing vectors
     * for the element before re-indexing to ensure consistency.
     */
    public function indexElement(ElementInterface $element): void
    {
        if (!($element instanceof Entry)) {
            return;
        }

        if (SmartSearch::getInstance()->exclusionService->isExcluded($element->id, $element->siteId)) {
            $this->deleteVector($element->id, $element->siteId);
            return;
        }

        if ($element->getUrl() === null) {
            throw SearchException::indexEntryMissingUrl($element->id, $element->siteId);
        }

        $text = $this->extractTextFromElement($element);

        if (TextValidator::isEmpty($text)) {
            Logger::debug('No content to index for entry', [
                'entryId' => $element->id,
                'siteId' => $element->siteId,
                'reason' => 'extracted text is empty after stripping fields',
            ]);
            return;
        }

        $hash = hash('sha256', $text);
        $chunks = $this->chunkText($text);
        $totalChunks = count($chunks);

        $fingerprint = SmartSearch::getInstance()->databaseService->getStoredEntryFingerprint($element->id, $element->siteId);
        if ($fingerprint['hash'] === $hash && $fingerprint['chunkCount'] === $totalChunks) {
            Logger::debug('Skipping unchanged entry', [
                'entryId' => $element->id,
                'siteId' => $element->siteId,
                'chunks' => $totalChunks,
            ]);
            return;
        }

        foreach ($chunks as $index => $chunkText) {
            $embedding = $this->generateEmbedding($chunkText);
            $this->storeVector(
                $element->id,
                $element->siteId,
                $index,
                $totalChunks,
                $embedding,
                $chunkText,
                $hash,
            );
        }

        $this->deleteExcessChunks($element->id, $element->siteId, $totalChunks);

        Logger::info('Indexed entry', ['entryId' => $element->id, 'chunks' => $totalChunks]);
    }

    /**
     * Store a vector embedding in the database.
     *
     * Uses an upsert (INSERT ... ON CONFLICT DO UPDATE) so re-indexing the same
     * chunk overwrites the previous vector and content.
     *
     * @throws \ghoststreet\craftsmartsearch\exceptions\DatabaseException If database connection fails or query fails
     */
    public function storeVector(
        int $elementId,
        int $siteId,
        int $chunkIndex,
        int $totalChunks,
        array $vector,
        ?string $content = null,
        ?string $contentHash = null,
    ): void {
        $databaseService = SmartSearch::getInstance()->databaseService;
        $db = $databaseService->getConnection();
        $table = $databaseService->getQualifiedTable();

        $vectorString = (string) new Vector($vector);

        try {
            $stmt = $db->prepare("
                INSERT INTO {$table} (\"elementId\", \"siteId\", \"chunkIndex\", \"totalChunks\", vector, content, \"contentHash\", \"dateUpdated\")
                VALUES (:elementId, :siteId, :chunkIndex, :totalChunks, :vector::vector, :content, :contentHash, CURRENT_TIMESTAMP)
                ON CONFLICT(\"elementId\", \"siteId\", \"chunkIndex\")
                DO UPDATE SET vector = EXCLUDED.vector, content = EXCLUDED.content, \"totalChunks\" = EXCLUDED.\"totalChunks\", \"contentHash\" = EXCLUDED.\"contentHash\", \"dateUpdated\" = CURRENT_TIMESTAMP
            ");
            $stmt->execute([
                ':elementId' => $elementId,
                ':siteId' => $siteId,
                ':chunkIndex' => $chunkIndex,
                ':totalChunks' => $totalChunks,
                ':vector' => $vectorString,
                ':content' => $content,
                ':contentHash' => $contentHash,
            ]);
        } catch (PDOException $e) {
            Logger::exception($e, 'storeVector', ['elementId' => $elementId, 'chunkIndex' => $chunkIndex]);
            throw DatabaseException::queryFailed('storeVector', $e);
        }
    }

    /**
     * Delete all vectors for an element, optionally scoped to a specific site.
     *
     * @throws \ghoststreet\craftsmartsearch\exceptions\DatabaseException If database connection fails or query fails
     */
    /**
     * Delete chunk rows whose chunkIndex is at or above the current total.
     *
     * Used after re-indexing to prune stale tail chunks left over from a
     * previous, longer version of the same entry. storeVector() upserts by
     * (elementId, siteId, chunkIndex), so chunk slots beyond the new tail
     * would otherwise persist with embeddings that no longer match any text.
     *
     * @throws \ghoststreet\craftsmartsearch\exceptions\DatabaseException
     */
    private function deleteExcessChunks(int $elementId, int $siteId, int $totalChunks): void
    {
        $db = SmartSearch::getInstance()->databaseService->getConnection();
        $table = SmartSearch::getInstance()->databaseService->getQualifiedTable();

        try {
            $stmt = $db->prepare("DELETE FROM {$table} WHERE \"elementId\" = :elementId AND \"siteId\" = :siteId AND \"chunkIndex\" >= :totalChunks");
            $stmt->execute([
                ':elementId' => $elementId,
                ':siteId' => $siteId,
                ':totalChunks' => $totalChunks,
            ]);
        } catch (PDOException $e) {
            Logger::exception($e, 'deleteExcessChunks', ['elementId' => $elementId, 'siteId' => $siteId, 'totalChunks' => $totalChunks]);
            throw DatabaseException::queryFailed('deleteExcessChunks', $e);
        }
    }

    public function deleteVector(int $elementId, ?int $siteId = null): void
    {
        $db = SmartSearch::getInstance()->databaseService->getConnection();
        $table = SmartSearch::getInstance()->databaseService->getQualifiedTable();

        try {
            if ($siteId !== null) {
                $stmt = $db->prepare("DELETE FROM {$table} WHERE \"elementId\" = :elementId AND \"siteId\" = :siteId");
                $stmt->execute([':elementId' => $elementId, ':siteId' => $siteId]);
            } else {
                $stmt = $db->prepare("DELETE FROM {$table} WHERE \"elementId\" = :elementId");
                $stmt->execute([':elementId' => $elementId]);
            }
        } catch (PDOException $e) {
            Logger::exception($e, 'deleteVector', ['elementId' => $elementId, 'siteId' => $siteId]);
            throw DatabaseException::queryFailed('deleteVector', $e);
        }
    }
}

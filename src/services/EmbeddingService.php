<?php

namespace ghoststreet\craftaisearch\services;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\db\AssetQuery;
use craft\elements\db\CategoryQuery;
use craft\elements\db\ElementQuery;
use craft\elements\db\EntryQuery;
use craft\elements\db\TagQuery;
use craft\elements\Entry;
use DateTime;
use ghoststreet\craftaisearch\AiSearch;
use ghoststreet\craftaisearch\exceptions\DatabaseException;
use ghoststreet\craftaisearch\exceptions\EmbeddingException;
use ghoststreet\craftaisearch\helpers\ContentPatterns;
use ghoststreet\craftaisearch\helpers\Logger;
use ghoststreet\craftaisearch\helpers\TextValidator;
use ghoststreet\craftaisearch\helpers\TokenEstimator;
use ghoststreet\craftaisearch\helpers\VectorFormatter;
use OpenAI\Client;
use OpenAI\Exceptions\ErrorException;
use PDOException;
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
        return AiSearch::getInstance()->openAIClientFactory->getClient();
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

        $settings = AiSearch::getInstance()->getSettings();
        $model = $model ?? $settings->hybridEmbeddingModel;

        $normalizedText = trim($text);
        $requestCacheKey = md5($normalizedText . '_' . $model);
        $persistentCacheKey = 'aisearch_embedding_' . $requestCacheKey;

        if (isset(self::$requestEmbeddingCache[$requestCacheKey])) {
            Logger::debug('Embedding cache hit (request-level)', [
                'textPreview' => substr($normalizedText, 0, 50) . '...',
            ]);
            return self::$requestEmbeddingCache[$requestCacheKey];
        }

        $cache = $useCache ? Craft::$app->getCache() : null;

        if ($cache !== null) {
            $cachedEmbedding = $cache->get($persistentCacheKey);

            if ($cachedEmbedding !== false && is_array($cachedEmbedding)) {
                self::$requestEmbeddingCache[$requestCacheKey] = $cachedEmbedding;
                return $cachedEmbedding;
            }
        }

        try {
            $client = $this->getOpenAIClient();

            $params = [
                'model' => $model,
                'input' => $normalizedText,
            ];

            $params['dimensions'] = $settings->vectorDimensions;

            $response = $client->embeddings()->create($params);

            $embedding = $response->embeddings[0]->embedding;

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
        if ($fieldValue instanceof DateTime) {
            return $fieldValue->format('F j, Y');
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

        if (is_iterable($fieldValue)) {
            return $this->extractTextFromIterable($fieldValue);
        }

        if (is_object($fieldValue) && method_exists($fieldValue, 'getPlainText')) {
            return $fieldValue->getPlainText();
        }

        if (is_object($fieldValue) && method_exists($fieldValue, '__toString')) {
            return strip_tags((string)$fieldValue);
        }

        return '';
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
     * Used by the debug view to verify which fields contribute text, which are skipped,
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
                ];
                continue;
            }

            $extracted = $this->extractTextFromFieldValue($field, $fieldValue);

            if (!TextValidator::isNotEmpty($extracted)) {
                $rows[] = [
                    'handle' => $field->handle,
                    'type' => $type,
                    'searchable' => true,
                    'indexed' => false,
                    'reason' => 'no extractable text',
                    'extractedText' => '',
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
            ];
        }

        return $rows;
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
        $settings = AiSearch::getInstance()->getSettings();

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

        if ($element->getUrl() === null) {
            Logger::debug('Skipping entry without URI', ['entryId' => $element->id]);
            return;
        }

        $allowedSections = AiSearch::getInstance()->getSettings()->indexableSections;

        if (!empty($allowedSections)) {
            $section = $element->getSection();
            if ($section === null || !in_array($section->handle, $allowedSections, true)) {
                Logger::debug('Skipping entry in excluded section', ['entryId' => $element->id]);
                return;
            }
        }

        $text = $this->extractTextFromElement($element);

        if (TextValidator::isEmpty($text)) {
            Logger::debug('No content to index for entry', ['entryId' => $element->id]);
            return;
        }

        $chunks = $this->chunkText($text);
        $totalChunks = count($chunks);

        foreach ($chunks as $index => $chunkText) {
            $embedding = $this->generateEmbedding($chunkText);
            $this->storeVector(
                $element->id,
                $element->siteId,
                $index,
                $totalChunks,
                $embedding,
                $chunkText
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
     * @throws \ghoststreet\craftaisearch\exceptions\DatabaseException If database connection fails or query fails
     */
    public function storeVector(
        int $elementId,
        int $siteId,
        int $chunkIndex,
        int $totalChunks,
        array $vector,
        ?string $content = null,
    ): void {
        $db = AiSearch::getInstance()->databaseService->getConnection();

        $vectorString = VectorFormatter::toPgVector($vector);

        try {
            $stmt = $db->prepare('
                INSERT INTO ' . DatabaseService::TABLE_NAME . ' ("elementId", "siteId", "chunkIndex", "totalChunks", vector, content, "dateUpdated")
                VALUES (:elementId, :siteId, :chunkIndex, :totalChunks, :vector::vector, :content, CURRENT_TIMESTAMP)
                ON CONFLICT("elementId", "siteId", "chunkIndex")
                DO UPDATE SET vector = EXCLUDED.vector, content = EXCLUDED.content, "totalChunks" = EXCLUDED."totalChunks", "dateUpdated" = CURRENT_TIMESTAMP
            ');

            $stmt->execute([
                ':elementId' => $elementId,
                ':siteId' => $siteId,
                ':chunkIndex' => $chunkIndex,
                ':totalChunks' => $totalChunks,
                ':vector' => $vectorString,
                ':content' => $content,
            ]);
        } catch (PDOException $e) {
            Logger::exception($e, 'storeVector', ['elementId' => $elementId, 'chunkIndex' => $chunkIndex]);
            throw DatabaseException::queryFailed('storeVector', $e);
        }
    }

    /**
     * Delete all vectors for an element, optionally scoped to a specific site.
     *
     * @throws \ghoststreet\craftaisearch\exceptions\DatabaseException If database connection fails or query fails
     */
    /**
     * Delete chunk rows whose chunkIndex is at or above the current total.
     *
     * Used after re-indexing to prune stale tail chunks left over from a
     * previous, longer version of the same entry. storeVector() upserts by
     * (elementId, siteId, chunkIndex), so chunk slots beyond the new tail
     * would otherwise persist with embeddings that no longer match any text.
     *
     * @throws \ghoststreet\craftaisearch\exceptions\DatabaseException
     */
    private function deleteExcessChunks(int $elementId, int $siteId, int $totalChunks): void
    {
        $db = AiSearch::getInstance()->databaseService->getConnection();

        try {
            $stmt = $db->prepare('DELETE FROM ' . DatabaseService::TABLE_NAME . ' WHERE "elementId" = :elementId AND "siteId" = :siteId AND "chunkIndex" >= :totalChunks');
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
        $db = AiSearch::getInstance()->databaseService->getConnection();

        try {
            if ($siteId !== null) {
                $stmt = $db->prepare('DELETE FROM ' . DatabaseService::TABLE_NAME . ' WHERE "elementId" = :elementId AND "siteId" = :siteId');
                $stmt->execute([':elementId' => $elementId, ':siteId' => $siteId]);
            } else {
                $stmt = $db->prepare('DELETE FROM ' . DatabaseService::TABLE_NAME . ' WHERE "elementId" = :elementId');
                $stmt->execute([':elementId' => $elementId]);
            }
        } catch (PDOException $e) {
            Logger::exception($e, 'deleteVector', ['elementId' => $elementId, 'siteId' => $siteId]);
            throw DatabaseException::queryFailed('deleteVector', $e);
        }
    }
}

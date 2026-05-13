<?php

namespace ghoststreet\craftaisearch\services;

use craft\elements\Entry;
use ghoststreet\craftaisearch\AiSearch;
use ghoststreet\craftaisearch\exceptions\SearchException;
use ghoststreet\craftaisearch\helpers\Logger;
use ghoststreet\craftaisearch\helpers\SearchResultDeduplicator;
use ghoststreet\craftaisearch\helpers\TimingProfiler;
use ghoststreet\craftaisearch\helpers\VectorFormatter;
use PDO;
use PDOException;
use yii\base\Component;

/**
 * Search Service — entry point for vector similarity searches.
 * Public search() always runs hybrid (vector + BM25 via RRF) for best quality.
 * semanticSearch / semanticSearchRaw remain available for internal callers
 * (e.g. HybridSearchService precomputed-vector path).
 */
class SearchService extends Component
{
    /** Over-fetch multiplier to ensure enough unique entries after chunk deduplication */
    private const OVERFETCH_MULTIPLIER = 3;

    /** Minimum number of rows to fetch regardless of the requested limit */
    private const MIN_OVERFETCH = 20;
    /**
     * @throws SearchException If database query fails
     */
    public function search(string $query, int $limit = 10, ?int $siteId = null, ?string $embeddingModel = null): array
    {
        return AiSearch::getInstance()->hybridSearchService->search($query, $limit, $siteId, $embeddingModel);
    }

    /**
     * Perform a semantic search and return results with loaded Craft Entry elements.
     *
     * @return array Array of ['element' => Entry, 'score' => float, 'content' => string, ...]
     * @throws SearchException If the vector query fails
     */
    public function semanticSearch(string $query, int $limit = 10, ?int $siteId = null, bool $applyThreshold = true, ?string $embeddingModel = null): array
    {
        $results = $this->semanticSearchRaw($query, $limit, $siteId, $applyThreshold, $embeddingModel);

        if (empty($results)) {
            return [];
        }

        return $this->loadAndAttachElements($results);
    }

    /**
     * Perform a raw semantic vector search against pgvector, returning database rows
     * without loading Craft elements. Supports precomputed vectors to avoid redundant
     * embedding generation when called from HybridSearchService.
     *
     * @param array|null $precomputedVector Reuse an already-generated query vector
     * @return array Raw result rows with elementId, siteId, similarity, and content
     * @throws SearchException If the vector query fails
     */
    public function semanticSearchRaw(string $query, int $limit = 10, ?int $siteId = null, bool $applyThreshold = true, ?string $embeddingModel = null, ?array $precomputedVector = null): array
    {
        $db = AiSearch::getInstance()->databaseService->getConnection();

        if ($precomputedVector !== null) {
            $queryVector = $precomputedVector;
        } else {
            $queryVector = TimingProfiler::profile(
                'Query embedding generation',
                fn() => AiSearch::getInstance()->embeddingService->generateEmbedding($query, true, $embeddingModel)
            );
        }

        $queryVectorString = VectorFormatter::toPgVector($queryVector);

        $settings = AiSearch::getInstance()->getSettings();
        $similarityThreshold = $applyThreshold ? max(0.0, min(1.0, (float)$settings->minimumSimilarityThreshold)) : 0.0;

        try {
            $sql = "
                SELECT
                    \"elementId\",
                    \"siteId\",
                    1 - (vector <=> :queryVector::vector) AS similarity,
                    content
                FROM " . DatabaseService::TABLE_NAME . "
            ";

            $conditions = [];
            $params = [':queryVector' => $queryVectorString];

            if ($siteId !== null) {
                $conditions[] = "\"siteId\" = :siteId";
                $params[':siteId'] = $siteId;
            }

            if ($similarityThreshold > 0.0) {
                $conditions[] = "1 - (vector <=> :queryVector::vector) >= :minThreshold";
                $params[':minThreshold'] = $similarityThreshold;
            }

            if (!empty($conditions)) {
                $sql .= " WHERE " . implode(' AND ', $conditions);
            }

            $overFetchLimit = max(self::MIN_OVERFETCH, $limit * self::OVERFETCH_MULTIPLIER);
            $sql .= " ORDER BY vector <=> :queryVector::vector ASC LIMIT {$overFetchLimit}";

            $stmt = $db->prepare($sql);
            $results = TimingProfiler::profile(
                'PostgreSQL vector query',
                function() use ($stmt, $params) {
                    $stmt->execute($params);
                    return $stmt->fetchAll(PDO::FETCH_ASSOC);
                },
                ['overFetchLimit' => $overFetchLimit]
            );
            Logger::debug('Vector query returned', ['rows' => count($results)]);

            $results = SearchResultDeduplicator::process(array_values($results), 'similarity', $limit);

            return $results;
        } catch (PDOException $e) {
            Logger::exception($e, 'semanticSearch', ['query' => substr($query, 0, 50)]);
            throw SearchException::vectorQueryFailed($e);
        }
    }

    /**
     * Load Craft Entry elements for raw search results and merge them into
     * the result array alongside scores and content.
     */
    private function loadAndAttachElements(array $results): array
    {
        $ids = array_unique(array_column($results, 'elementId'));
        $elements = Entry::find()->id($ids)->status(null)->indexBy('id')->all();

        $loaded = [];
        foreach ($results as $result) {
            $element = $elements[(int)$result['elementId']];

            $loaded[] = [
                'element' => $element,
                'score' => (float)$result['similarity'],
                'semanticScore' => (float)$result['similarity'],
                'meetsSemanticThreshold' => true,
                'content' => $result['content'],
            ];
        }

        return $loaded;
    }
}

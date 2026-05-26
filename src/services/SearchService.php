<?php

namespace ghoststreet\craftsmartsearch\services;

use ghoststreet\craftsmartsearch\SmartSearch;
use ghoststreet\craftsmartsearch\exceptions\SearchException;
use ghoststreet\craftsmartsearch\helpers\Logger;
use ghoststreet\craftsmartsearch\helpers\SearchResultDeduplicator;
use ghoststreet\craftsmartsearch\helpers\TimingProfiler;
use PDO;
use Pgvector\Vector;
use PDOException;
use yii\base\Component;

/**
 * Search Service — entry point for vector similarity searches.
 * Public search() always runs smart search (semantic + keyword via RRF) for best quality.
 * semanticSearchRaw remains available for internal callers
 * (e.g. SmartSearchService precomputed-vector path).
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
        return SmartSearch::getInstance()->smartSearchService->search($query, $limit, $siteId, $embeddingModel);
    }

    /**
     * Perform a raw semantic vector search against pgvector, returning database rows
     * without loading Craft elements. Supports precomputed vectors to avoid redundant
     * embedding generation when called from SmartSearchService.
     *
     * @param array|null $precomputedVector Reuse an already-generated query vector
     * @return array Raw result rows with elementId, siteId, similarity, and content
     * @throws SearchException If the vector query fails
     */
    public function semanticSearchRaw(string $query, int $limit = 10, ?int $siteId = null, ?string $embeddingModel = null, ?array $precomputedVector = null): array
    {
        $db = SmartSearch::getInstance()->databaseService->getConnection();

        if ($precomputedVector !== null) {
            $queryVector = $precomputedVector;
        } else {
            $queryVector = TimingProfiler::profile(
                'Query embedding generation',
                fn() => SmartSearch::getInstance()->embeddingService->generateEmbedding($query, true, $embeddingModel)
            );
        }

        $queryVectorString = (string) new Vector($queryVector);

        $table = SmartSearch::getInstance()->databaseService->getQualifiedTable();

        try {
            $sql = "
                SELECT
                    \"elementId\",
                    \"siteId\",
                    1 - (vector <=> :queryVector::vector) AS similarity,
                    content
                FROM {$table}
            ";

            $conditions = [];
            $params = [':queryVector' => $queryVectorString];

            if ($siteId !== null) {
                $conditions[] = "\"siteId\" = :siteId";
                $params[':siteId'] = $siteId;
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
}

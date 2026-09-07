<?php

namespace ghoststreet\craftsmartsearch\services;

use Craft;
use ghoststreet\craftsmartsearch\exceptions\SearchException;
use ghoststreet\craftsmartsearch\helpers\Logger;
use ghoststreet\craftsmartsearch\helpers\Ranker;
use ghoststreet\craftsmartsearch\helpers\SqlHelper;
use ghoststreet\craftsmartsearch\helpers\TimingProfiler;
use ghoststreet\craftsmartsearch\helpers\UsageTracker;
use ghoststreet\craftsmartsearch\models\Settings;
use ghoststreet\craftsmartsearch\SmartSearch;
use PDO;
use PDOException;
use yii\base\Component;

/**
 * Smart Search Service — combines semantic vector similarity and keyword scoring
 * using Reciprocal Rank Fusion (RRF) to produce a single ranked result list.
 *
 * This is the pgvector-backed type. Signal generation lives here; the fusion, boost
 * merge and element hydration it shares with every other type live in Ranker.
 *
 * @phpstan-import-type ChunkKey from Ranker
 * @phpstan-import-type ScoredEntry from Ranker
 */
class SmartSearchService extends Component
{
    public const EVENT_FORMAT_RESULT = 'formatSearchResult';

    /**
     * Ranking cache lifetime. Short, because it is the window in which a freshly
     * indexed entry can be missing from results.
     */
    private const RANKING_CACHE_TTL_SECONDS = 60;

    private const RANKING_CACHE_KEY_PREFIX = 'smart_search_ranking_';

    /**
     * HNSW candidate list size for the vector scan. Higher = better recall, slower.
     * pgvector's own default is 40; 20 trades some recall for latency.
     */
    private const HNSW_EF_SEARCH = 20;

    /**
     * Perform smart search combining semantic similarity and keyword scoring
     * using Reciprocal Rank Fusion (RRF) to merge both signal types.
     *
     * @param string[]|null $sections Restrict results to these section handles
     * @throws \ghoststreet\craftsmartsearch\exceptions\EmbeddingException If embedding generation fails
     * @throws \ghoststreet\craftsmartsearch\exceptions\SearchException If vector or Keyword query fails
     */
    public function search(string $query, int $limit = 10, ?int $siteId = null, ?string $embeddingModel = null, ?array $sections = null): array
    {
        $settings = SmartSearch::getInstance()->getSettings();

        $sectionIds = null;
        if (!empty($sections)) {
            $sectionIds = Ranker::resolveSectionIds($sections);
            if ($sectionIds === []) {
                Logger::debug('Section filter matched no known sections', ['sections' => $sections]);
                return [];
            }
        }

        $cacheKey = self::RANKING_CACHE_KEY_PREFIX . md5(implode('|', [
            $query,
            (string)$limit,
            (string)($siteId ?? ''),
            (string)($embeddingModel ?? ''),
            $sectionIds === null ? '' : implode(',', $sectionIds),
            Ranker::settingsFingerprint($settings),
            SmartSearch::getInstance()->databaseService->vectorsCacheToken(),
        ]));

        /*
         * Only the ranking is cached, never the payload: elements are still hydrated
         * fresh below, so a title or URL edit shows up immediately. The requesting site
         * is absent from the key on purpose, since it affects hydration, not ranking.
         *
         * The vectors token is in the key so indexing an entry invalidates every cached
         * ranking at once. Without it a newly indexed entry stayed missing from results
         * until the TTL expired, which is the one behaviour change this cache would
         * otherwise impose. The TTL now only bounds staleness from writes made by another
         * server that shares the store but not this cache.
         */
        $cache = Craft::$app->getCache();
        $scoredResults = $cache->get($cacheKey);

        if (is_array($scoredResults)) {
            /* No embedding call happened, so say so or Insights reports the search
               against no model at all. */
            UsageTracker::markEmbeddingCached($embeddingModel ?? $settings->embeddingModel);
        } else {
            $scoredResults = TimingProfiler::profile(
                'Ranking',
                fn() => $this->rankResults($query, $limit, $siteId, $embeddingModel, $sectionIds, $settings)
            );
            $cache->set($cacheKey, $scoredResults, self::RANKING_CACHE_TTL_SECONDS);
        }

        /* Dispatched before the element load, collected after it. Needs ranking settled. */
        $contentPrefetch = $this->prefetchChunkContent($scoredResults, $limit);

        $finalResults = TimingProfiler::profile('Load elements', fn() => Ranker::loadElements($scoredResults, $limit, $siteId));

        $finalResults = TimingProfiler::profile(
            'Attach chunk content',
            fn() => Ranker::attachChunkContent($finalResults, $contentPrefetch)
        );

        Logger::debug('Smart search final results', [
            'requestedLimit' => $limit,
            'returnedResults' => count($finalResults),
        ]);

        return $finalResults;
    }

    /**
     * Everything that produces the ranked candidate map: both signals, RRF fusion,
     * boosts and the sort. Split out of search() so it can be cached without caching
     * the hydrated elements.
     *
     * @param int[]|null $sectionIds
     * @return array<int, ScoredEntry> Sorted best-first
     */
    private function rankResults(
        string $query,
        int $limit,
        ?int $siteId,
        ?string $embeddingModel,
        ?array $sectionIds,
        Settings $settings,
    ): array {
        /*
         * Every wait here is arranged to cover another one. There is exactly one async
         * Postgres handle, so only one statement is in flight on it at a time, and the two
         * long waits available as cover are the embedding call and the vector query.
         *
         *   1. write the embedding request (longest wait, so it starts first)
         *   2. corrector lookup, then dispatch the keyword scan
         *   3. collect the embedding
         *   4. run the vector query, which keeps covering the keyword scan
         *   5. collect the keyword scan, then dispatch and collect the boost match
         *
         * The keyword scan is dispatched first of the two Postgres statements because it is
         * much the slower, so it needs both cover windows. Dispatching the boost match first
         * instead measured 50 ms worse.
         */
        $embeddingPrefetch = TimingProfiler::profile(
            'Embedding dispatch',
            fn() => SmartSearch::getInstance()->embeddingService->dispatchEmbedding($query, $embeddingModel)
        );

        $keywordPrefetch = TimingProfiler::profile(
            'Keyword prefetch dispatch',
            fn() => SmartSearch::getInstance()->keywordSearchService->prefetchScores($query, $siteId, $sectionIds)
        );

        $queryVector = TimingProfiler::profile('Query embedding wait', $embeddingPrefetch);

        $semanticResults = $this->semanticSearchRaw(
            $queryVector,
            min($settings->maxSemanticResults, $limit * 10),
            $siteId,
            $sectionIds,
        );

        $keywordResults = TimingProfiler::profile(
            'Keyword scoring',
            fn() => $keywordPrefetch !== null
                ? $keywordPrefetch()
                : SmartSearch::getInstance()->keywordSearchService->calculateScores($query, $siteId, $sectionIds)
        );

        /* Only now is the single async handle free again. */
        $boostPrefetch = SmartSearch::getInstance()->boostService->prefetchMatch($query, $siteId);

        $boosts = TimingProfiler::profile(
            'Boost match',
            fn() => $boostPrefetch !== null
                ? $boostPrefetch()
                : SmartSearch::getInstance()->boostService->match($query, $siteId)
        );

        $semanticLookup = Ranker::semanticLookup($semanticResults);
        $keywordLookup = Ranker::keywordLookup($keywordResults);

        $scoredResults = Ranker::fuse($semanticLookup, $keywordLookup, $settings);

        Logger::debug('Smart search RRF', [
            'semanticRawRows' => count($semanticResults),
            'semanticUniqueElements' => count($semanticLookup),
            'keywordUniqueElements' => count($keywordLookup),
            'survived' => count($scoredResults),
            'minSemanticThreshold' => $settings->minSemanticThreshold,
            'rrfSemanticWeight' => $settings->rrfSemanticWeight,
            'rrfKeywordWeight' => $settings->rrfKeywordWeight,
        ]);

        Ranker::applyBoosts($scoredResults, $boosts);

        uasort($scoredResults, fn($a, $b) => $b['rrfScore'] <=> $a['rrfScore']);

        return $scoredResults;
    }

    /**
     * Raw pgvector similarity query. Over-fetches because one entry can own many
     * chunk rows, then collapses to that entry's best-scoring chunk.
     *
     * @param int[]|null $sectionIds Restrict candidates to these Craft section ids
     * @return array Rows with elementId, siteId, similarity, and content
     * @throws SearchException If the vector query fails
     */
    private function semanticSearchRaw(array $queryVector, int $limit, ?int $siteId, ?array $sectionIds): array
    {
        $databaseService = SmartSearch::getInstance()->databaseService;
        $table = $databaseService->getQualifiedTable();

        try {
            $conditions = [];
            $params = [':queryVector' => json_encode($queryVector)];

            if ($siteId !== null) {
                $conditions[] = "\"siteId\" = :siteId";
                $params[':siteId'] = $siteId;
            }

            if (!empty($sectionIds)) {
                [$inList, $sectionParams] = SqlHelper::namedInList($sectionIds, 'sectionId');
                $conditions[] = "\"sectionId\" IN {$inList}";
                $params += $sectionParams;
            }

            $where = empty($conditions) ? '' : ' WHERE ' . implode(' AND ', $conditions);
            $overFetchLimit = max(Ranker::MIN_OVERFETCH, $limit * Ranker::OVERFETCH_MULTIPLIER);

            /*
             * Returns chunk identity, never chunk text: only the rows that survive
             * fusion are ever read, and their text is fetched by chunk key later.
             *
             * The vector is bound in a CTE because emulated prepares inline it once
             * per mention, and it is ~13KB.
             */
            $sql = "
                WITH q AS (SELECT :queryVector::vector AS qv)
                SELECT \"elementId\", \"siteId\", \"chunkIndex\", similarity
                FROM (
                    SELECT DISTINCT ON (\"elementId\", \"siteId\")
                        \"elementId\", \"siteId\", \"chunkIndex\", similarity
                    FROM (
                        SELECT
                            \"elementId\",
                            \"siteId\",
                            \"chunkIndex\",
                            1 - (vector <=> q.qv) AS similarity
                        FROM {$table}, q{$where}
                        ORDER BY vector <=> q.qv ASC
                        LIMIT {$overFetchLimit}
                    ) nearest_chunks
                    ORDER BY \"elementId\", \"siteId\", similarity DESC
                ) best_per_entry
                ORDER BY similarity DESC
                LIMIT {$limit}
            ";

            /*
             * SET LOCAL must stay in the same send as the SELECT: Postgres runs a
             * multi-statement send inside an implicit transaction, which is what scopes
             * the GUC and reverts it afterwards. Splitting these into separate sends, or
             * swapping SET LOCAL for a session SET, leaks the setting into other clients
             * sharing the pooled backend.
             */
            $sql = 'SET LOCAL hnsw.ef_search = ' . self::HNSW_EF_SEARCH . '; ' . $sql;

            $rows = TimingProfiler::profile(
                'PostgreSQL vector query',
                fn() => $databaseService->fetchAll($sql, $params, 'semanticSearch'),
                ['overFetchLimit' => $overFetchLimit]
            );

            return $rows;
        } catch (PDOException $e) {
            Logger::exception($e, 'semanticSearch');
            throw SearchException::vectorQueryFailed($e);
        }
    }

    /**
     * Dispatch a fetch of the chunk text for the best-scoring candidates, returning a
     * callable that collects `"elementId-siteId-chunkIndex" => body`. Null when there is
     * nothing to fetch.
     *
     * @param array<int, array<string, mixed>> $scoredResults Sorted best-first
     */
    private function prefetchChunkContent(array $scoredResults, int $limit): ?callable
    {
        $chunks = [];
        foreach ($scoredResults as $data) {
            if ($data['chunk'] !== null) {
                $chunks[] = $data['chunk'];
            }
            /* Window must match Ranker::loadElements', or a survivor loses its text. */
            if (count($chunks) >= $limit + Ranker::ELEMENT_WINDOW_SLACK) {
                break;
            }
        }

        if ($chunks === []) {
            return null;
        }

        $databaseService = SmartSearch::getInstance()->databaseService;
        $table = $databaseService->getQualifiedTable();

        $tuples = [];
        $params = [];
        foreach ($chunks as $i => [$elementId, $siteId, $chunkIndex]) {
            $tuples[] = "(:e{$i}, :s{$i}, :c{$i})";
            $params[":e{$i}"] = $elementId;
            $params[":s{$i}"] = $siteId;
            $params[":c{$i}"] = $chunkIndex;
        }

        $sql = "SELECT \"elementId\", \"siteId\", \"chunkIndex\", body
                FROM {$table}
                WHERE (\"elementId\", \"siteId\", \"chunkIndex\") IN (" . implode(', ', $tuples) . ')';

        $connection = $databaseService->getAsyncConnection();
        if ($connection !== null) {
            [$pgSql, $values] = SqlHelper::toPositional($sql, $params);
            if (@pg_send_query_params($connection, $pgSql, $values) !== false) {
                return static function() use ($connection): array {
                    $bodies = [];
                    while (($result = pg_get_result($connection)) !== false) {
                        if (pg_result_error($result) !== '') {
                            Logger::warning('Chunk content fetch failed', ['error' => pg_result_error($result)]);
                            continue;
                        }
                        foreach (pg_fetch_all($result) ?: [] as $row) {
                            $bodies["{$row['elementId']}-{$row['siteId']}-{$row['chunkIndex']}"] = (string)$row['body'];
                        }
                    }
                    return $bodies;
                };
            }
        }

        return function() use ($databaseService, $sql, $params): array {
            $stmt = $databaseService->executeStatement($sql, $params, 'prefetchChunkContent');
            $bodies = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $bodies["{$row['elementId']}-{$row['siteId']}-{$row['chunkIndex']}"] = (string)$row['body'];
            }
            return $bodies;
        };
    }
}

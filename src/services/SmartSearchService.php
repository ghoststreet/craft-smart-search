<?php

namespace ghoststreet\craftsmartsearch\services;

use Craft;
use craft\elements\Entry;
use ghoststreet\craftsmartsearch\exceptions\SearchException;
use ghoststreet\craftsmartsearch\helpers\Logger;
use ghoststreet\craftsmartsearch\helpers\SqlHelper;
use ghoststreet\craftsmartsearch\helpers\TimingProfiler;
use ghoststreet\craftsmartsearch\models\Settings;
use ghoststreet\craftsmartsearch\SmartSearch;
use PDO;
use PDOException;
use yii\base\Component;

/**
 * Smart Search Service — combines semantic vector similarity and keyword scoring
 * using Reciprocal Rank Fusion (RRF) to produce a single ranked result list.
 *
 * Each signal contributes a weighted RRF score; results appearing in only one signal
 * receive a configurable penalty. Semantic-only results below a minimum threshold
 * are excluded entirely.
 *
 * @phpstan-type SignalEntry array{score: float, rank: int, content: string}
 * @phpstan-type ScoredEntry array{rrfScore: float, semanticScore: float, semanticRank: ?int, keywordScore: float, keywordRank: ?int, content: string}
 */
class SmartSearchService extends Component
{
    public const EVENT_FORMAT_RESULT = 'formatSearchResult';

    /** Rank damping constant for Reciprocal Rank Fusion. */
    private const RANK_OFFSET = 60;

    /** Over-fetch multiplier to ensure enough unique entries after chunk deduplication */
    private const OVERFETCH_MULTIPLIER = 3;

    /** Minimum number of rows to fetch regardless of the requested limit */
    private const MIN_OVERFETCH = 20;

    /**
     * Spare candidates hydrated alongside each page of $limit, covering the few
     * dropped for being deleted or URL-less. If more than this are dropped, the
     * next window is fetched — correct either way, just one more query.
     */
    private const ELEMENT_WINDOW_SLACK = 5;

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
            $sectionIds = $this->resolveSectionIds($sections);
            if ($sectionIds === []) {
                Logger::debug('Section filter matched no known sections', ['sections' => $sections]);
                return [];
            }
        }

        /*
         * Order matters: the keyword scan is dispatched before the embedding call so
         * Postgres runs it while PHP blocks on OpenAI. Null = no async handle, and the
         * scan runs inline below instead.
         */
        $keywordPrefetch = TimingProfiler::profile(
            'Keyword prefetch dispatch',
            fn() => SmartSearch::getInstance()->keywordSearchService->prefetchScores($query, $siteId, $sectionIds)
        );

        $queryVector = TimingProfiler::profile(
            'Query embedding generation',
            fn() => SmartSearch::getInstance()->embeddingService->generateEmbedding($query, $embeddingModel)
        );

        $keywordResults = TimingProfiler::profile(
            'Keyword scoring',
            fn() => $keywordPrefetch !== null
                ? $keywordPrefetch()
                : SmartSearch::getInstance()->keywordSearchService->calculateScores($query, $siteId, $sectionIds)
        );

        /* Dispatched before the vector query, collected after it. */
        $boostPrefetch = SmartSearch::getInstance()->boostService->prefetchMatch($query, $siteId);

        $semanticResults = $this->semanticSearchRaw(
            $queryVector,
            min($settings->maxSemanticResults, $limit * 10),
            $siteId,
            $sectionIds,
        );

        $semanticLookup = $this->buildSemanticLookup($semanticResults);
        $keywordLookup = $this->buildKeywordLookup($keywordResults);

        $scoredResults = $this->fuse($semanticLookup, $keywordLookup, $settings);

        Logger::debug('Smart search RRF', [
            'semanticRawRows' => count($semanticResults),
            'semanticUniqueElements' => count($semanticLookup),
            'keywordUniqueElements' => count($keywordLookup),
            'survived' => count($scoredResults),
            'minSemanticThreshold' => $settings->minSemanticThreshold,
            'rrfSemanticWeight' => $settings->rrfSemanticWeight,
            'rrfKeywordWeight' => $settings->rrfKeywordWeight,
        ]);

        $boosts = TimingProfiler::profile(
            'Boost match',
            fn() => $boostPrefetch !== null
                ? $boostPrefetch()
                : SmartSearch::getInstance()->boostService->match($query, $siteId)
        );
        foreach ($boosts as $elementId => $weight) {
            if (isset($scoredResults[$elementId])) {
                $scoredResults[$elementId]['rrfScore'] += $weight;
            } else {
                $scoredResults[$elementId] = [
                    'rrfScore' => $weight,
                    'semanticScore' => 0.0, 'semanticRank' => 0,
                    'keywordScore' => 0.0, 'keywordRank' => 0,
                    'content' => '',
                    'chunk' => null,
                ];
            }
        }

        uasort($scoredResults, fn($a, $b) => $b['rrfScore'] <=> $a['rrfScore']);

        /* Dispatched before the element load, collected after it. Needs ranking settled. */
        $contentPrefetch = $this->prefetchChunkContent($scoredResults, $limit);

        $finalResults = TimingProfiler::profile('Load elements', fn() => $this->loadElementsWithScores($scoredResults, $limit));

        $finalResults = TimingProfiler::profile(
            'Attach chunk content',
            fn() => $this->attachChunkContent($finalResults, $contentPrefetch)
        );

        Logger::debug('Smart search final results', [
            'requestedLimit' => $limit,
            'returnedResults' => count($finalResults),
        ]);

        return $finalResults;
    }

    /**
     * Reciprocal Rank Fusion over the two ranked signal lookups.
     *
     * Per-signal contribution: weight / (RANK_OFFSET + rank). Entries below
     * minSemanticThreshold that lack a keyword hit are dropped as semantic noise.
     *
     * @param array<int, SignalEntry> $semanticLookup
     * @param array<int, SignalEntry> $keywordLookup
     * @return array<int, ScoredEntry>
     */
    private function fuse(array $semanticLookup, array $keywordLookup, Settings $settings): array
    {
        $allIds = array_keys($semanticLookup + $keywordLookup);

        $scored = [];
        foreach ($allIds as $id) {
            $hasSemantic = isset($semanticLookup[$id]);
            $hasKeyword = isset($keywordLookup[$id]);
            $semanticScore = $hasSemantic ? $semanticLookup[$id]['score'] : 0.0;

            if ($hasSemantic && !$hasKeyword && $semanticScore < $settings->minSemanticThreshold) {
                continue;
            }

            $rrfScore = 0.0;
            if ($hasSemantic) {
                $rrfScore += $settings->rrfSemanticWeight / (self::RANK_OFFSET + $semanticLookup[$id]['rank']);
            }
            if ($hasKeyword) {
                $rrfScore += $settings->rrfKeywordWeight / (self::RANK_OFFSET + $keywordLookup[$id]['rank']);
            }

            if ($hasKeyword && $keywordLookup[$id]['score'] >= 0.5) {
                $rrfScore += $settings->rrfKeywordWeight / self::RANK_OFFSET;
            }

            $scored[$id] = [
                'rrfScore' => $rrfScore,
                'semanticScore' => $semanticScore,
                'semanticRank' => $hasSemantic ? $semanticLookup[$id]['rank'] : null,
                'keywordScore' => $hasKeyword ? $keywordLookup[$id]['score'] : 0.0,
                'keywordRank' => $hasKeyword ? $keywordLookup[$id]['rank'] : null,
                /* A semantic hit's text must come from its own winning chunk, fetched by
                   key later; only a keyword-only hit already carries its text. */
                'chunk' => $hasSemantic ? $semanticLookup[$id]['chunk'] : null,
                'content' => $hasSemantic ? '' : ($keywordLookup[$id]['content'] ?? ''),
            ];
        }

        return $scored;
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
            $overFetchLimit = max(self::MIN_OVERFETCH, $limit * self::OVERFETCH_MULTIPLIER);

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
            /* Window must match loadElementsWithScores', or a survivor loses its text. */
            if (count($chunks) >= $limit + self::ELEMENT_WINDOW_SLACK) {
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

    /**
     * Fill each result's excerpt source from the fetched chunk text. A result whose chunk
     * is missing keeps the content it already had, so a failed fetch costs an excerpt
     * rather than a result.
     */
    private function attachChunkContent(array $finalResults, ?callable $contentPrefetch): array
    {
        if ($contentPrefetch === null) {
            return $finalResults;
        }

        $bodies = $contentPrefetch();

        foreach ($finalResults as &$result) {
            $chunk = $result['chunk'] ?? null;
            if ($chunk === null) {
                continue;
            }
            $key = implode('-', $chunk);
            if (isset($bodies[$key])) {
                $result['content'] = $bodies[$key];
            }
        }
        unset($result);

        return $finalResults;
    }

    /**
     * Resolve section handles to ids. Unknown handles are dropped, so an
     * empty return means the filter cannot match anything.
     *
     * @param string[] $handles
     * @return int[]
     */
    private function resolveSectionIds(array $handles): array
    {
        return array_values(array_filter(array_map(
            static fn(string $h) => Craft::$app->getEntries()->getSectionByHandle($h)?->id,
            $handles,
        )));
    }

    /**
     * Index semantic results by elementId with rank and score for RRF lookup.
     */
    private function buildSemanticLookup(array $semanticResults): array
    {
        $lookup = [];
        $rank = 1;

        foreach ($semanticResults as $result) {
            $elementId = (int)$result['elementId'];
            $lookup[$elementId] = [
                'score' => (float)$result['similarity'],
                'rank' => $rank++,
                'chunk' => [(int)$result['elementId'], (int)$result['siteId'], (int)$result['chunkIndex']],
            ];
        }

        return $lookup;
    }

    /**
     * Index Keyword results by elementId with rank and score for RRF lookup.
     */
    private function buildKeywordLookup(array $keywordResults): array
    {
        $lookup = [];
        $rank = 1;

        foreach ($keywordResults as $score) {
            if ($score['keywordScore'] > 0) {
                $lookup[$score['elementId']] = [
                    'score' => $score['keywordScore'],
                    'rank' => $rank++,
                    'content' => $score['content'] ?? '',
                ];
            }
        }

        return $lookup;
    }

    /**
     * Load Craft Entry elements for the top-scored results and build the
     * final response array with all score dimensions attached.
     */
    private function loadElementsWithScores(array $scoredResults, int $limit): array
    {
        $allIds = array_keys($scoredResults);

        $missingCount = 0;
        $noUrlCount = 0;
        $loadedCount = 0;
        $results = [];

        /*
         * Hydrates a window at a time in rank order, stopping at $limit survivors.
         * Candidates past the window exist only to cover the few dropped as deleted
         * or URL-less.
         */
        foreach (array_chunk($allIds, $limit + self::ELEMENT_WINDOW_SLACK) as $idBatch) {
            $elements = Entry::find()->id($idBatch)->indexBy('id')->all();
            $loadedCount += count($elements);

            foreach ($idBatch as $id) {
                if (!isset($elements[$id])) {
                    $missingCount++;
                    continue;
                }

                $element = $elements[$id];
                if ($element->getUrl() === null) {
                    $noUrlCount++;
                    continue;
                }

                $data = $scoredResults[$id];

                $results[] = [
                    'element' => $element,
                    'score' => $data['rrfScore'],
                    'semanticScore' => $data['semanticScore'],
                    'semanticRank' => $data['semanticRank'],
                    'keywordScore' => $data['keywordScore'],
                    'keywordRank' => $data['keywordRank'],
                    'smartRank' => count($results) + 1,
                    'content' => $data['content'],
                    'chunk' => $data['chunk'],
                ];

                if (count($results) >= $limit) {
                    break 2;
                }
            }
        }

        Logger::debug('loadElementsWithScores filtering', [
            'scoredCandidates' => count($allIds),
            'elementsLoadedFromCraft' => $loadedCount,
            'missingInCraft' => $missingCount,
            'noUrl' => $noUrlCount,
            'finalResults' => count($results),
            'limit' => $limit,
        ]);

        return $results;
    }
}

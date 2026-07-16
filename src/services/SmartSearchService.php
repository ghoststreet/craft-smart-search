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

        $queryVector = TimingProfiler::profile(
            'Query embedding generation',
            fn() => SmartSearch::getInstance()->embeddingService->generateEmbedding($query, $embeddingModel)
        );

        $keywordResults = TimingProfiler::profile(
            'Keyword scoring',
            fn() => SmartSearch::getInstance()->keywordSearchService->calculateScores($query, $siteId, $sectionIds)
        );

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

        $boosts = SmartSearch::getInstance()->boostService->match($query, $siteId);
        foreach ($boosts as $elementId => $weight) {
            if (isset($scoredResults[$elementId])) {
                $scoredResults[$elementId]['rrfScore'] += $weight;
            } else {
                $scoredResults[$elementId] = [
                    'rrfScore' => $weight,
                    'semanticScore' => 0.0, 'semanticRank' => 0,
                    'keywordScore' => 0.0, 'keywordRank' => 0,
                    'content' => '',
                ];
            }
        }

        uasort($scoredResults, fn($a, $b) => $b['rrfScore'] <=> $a['rrfScore']);

        $finalResults = $this->loadElementsWithScores($scoredResults, $limit);

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
                'content' => $semanticLookup[$id]['content'] ?? $keywordLookup[$id]['content'] ?? '',
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
        $db = $databaseService->getConnection();
        $table = $databaseService->getQualifiedTable();

        try {
            $sql = "
                SELECT
                    \"elementId\",
                    \"siteId\",
                    1 - (vector <=> :queryVector::vector) AS similarity,
                    body AS content
                FROM {$table}
            ";

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

            if (!empty($conditions)) {
                $sql .= " WHERE " . implode(' AND ', $conditions);
            }

            $overFetchLimit = max(self::MIN_OVERFETCH, $limit * self::OVERFETCH_MULTIPLIER);
            $sql .= " ORDER BY vector <=> :queryVector::vector ASC LIMIT {$overFetchLimit}";

            $rows = TimingProfiler::profile(
                'PostgreSQL vector query',
                function() use ($db, $sql, $params) {
                    $db->beginTransaction();
                    try {
                        $db->exec('SET LOCAL hnsw.ef_search = ' . self::HNSW_EF_SEARCH);
                        $stmt = $db->prepare($sql);
                        $stmt->execute($params);
                        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        $db->commit();

                        return $rows;
                    } catch (\Throwable $e) {
                        if ($db->inTransaction()) {
                            try {
                                $db->rollBack();
                            } catch (\Throwable) {
                            }
                        }

                        throw $e;
                    }
                },
                ['overFetchLimit' => $overFetchLimit]
            );

            return $this->bestChunkPerEntry($rows, $limit);
        } catch (PDOException $e) {
            Logger::exception($e, 'semanticSearch');
            throw SearchException::vectorQueryFailed($e);
        }
    }

    /**
     * Collapse chunk rows to the highest-similarity row per entry, then sort and limit.
     */
    private function bestChunkPerEntry(array $rows, int $limit): array
    {
        $best = [];

        foreach ($rows as $row) {
            $key = $row['elementId'] . '-' . $row['siteId'];
            if (!isset($best[$key]) || (float)$row['similarity'] > (float)$best[$key]['similarity']) {
                $best[$key] = $row;
            }
        }

        $best = array_values($best);
        usort($best, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

        Logger::debug('Vector query', [
            'rowsIn' => count($rows),
            'uniqueElements' => count($best),
            'collapsedChunks' => count($rows) - count($best),
            'limit' => $limit,
        ]);

        return array_slice($best, 0, $limit);
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
                'content' => $result['content'],
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
        $elements = Entry::find()->id($allIds)->indexBy('id')->all();

        $missingCount = 0;
        $noUrlCount = 0;
        $results = [];

        foreach ($allIds as $id) {
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
            ];

            if (count($results) >= $limit) {
                break;
            }
        }

        Logger::debug('loadElementsWithScores filtering', [
            'scoredCandidates' => count($allIds),
            'elementsLoadedFromCraft' => count($elements),
            'missingInCraft' => $missingCount,
            'noUrl' => $noUrlCount,
            'finalResults' => count($results),
            'limit' => $limit,
        ]);

        return $results;
    }
}

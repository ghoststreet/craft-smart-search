<?php

namespace ghoststreet\craftsmartsearch\helpers;

use Craft;
use craft\elements\Entry;
use ghoststreet\craftsmartsearch\models\Settings;
use ghoststreet\craftsmartsearch\SmartSearch;

/**
 * Scoring shared by every engine: Reciprocal Rank Fusion over two ranked signals,
 * the boost merge, and the element hydration that turns a scored id map into results.
 *
 * @phpstan-type ChunkKey array{0: int, 1: int, 2: int}
 * @phpstan-type SignalEntry array{score: float, rank: int, chunk: ChunkKey, coverage?: float}
 * @phpstan-type ScoredEntry array{rrfScore: float, semanticScore: float, semanticRank: ?int, keywordScore: float, keywordRank: ?int, chunk: ?ChunkKey, siteId: int}
 * @phpstan-type BoostHit array{weight: float, siteId: int}
 */
final class Ranker
{
    /** Rank damping constant for Reciprocal Rank Fusion. */
    public const RANK_OFFSET = 60;

    /** Over-fetch multiplier to ensure enough unique entries after chunk deduplication */
    public const OVERFETCH_MULTIPLIER = 3;

    /** Minimum number of rows to fetch regardless of the requested limit */
    public const MIN_OVERFETCH = 20;

    /** A keyword score at or above this (on the 0..1 squashed scale) earns a strong-hit bonus. */
    private const STRONG_KEYWORD_SCORE = 0.5;

    /** A word match alone must cover more than this share of the query's words: both of two, three of four. */
    private const MIN_KEYWORD_COVERAGE = 0.5;

    /**
     * Spare candidates hydrated alongside each page of $limit, covering the few
     * dropped for being deleted. If more than this are dropped, the
     * next window is fetched; correct either way, just one more query.
     */
    public const ELEMENT_WINDOW_SLACK = 5;

    /**
     * Ranking cache lifetime. Short, because it is the window in which a freshly
     * indexed entry can be missing from results.
     */
    public const RANKING_CACHE_TTL_SECONDS = 60;

    /**
     * The ranked candidate map cached under $key, computed by $rank on a miss. Cached
     * without the hydrated elements, so a hit still reflects deletes and URL changes.
     *
     * @param callable(): array<int, ScoredEntry> $rank
     * @return array<int, ScoredEntry>
     */
    public static function cachedRanking(string $key, string $model, callable $rank): array
    {
        $cache = Craft::$app->getCache();
        $scored = $cache->get($key);

        if (is_array($scored)) {
            if (!SmartSearch::getInstance()->vectorSources->isLocal()) {
                UsageTracker::markEmbeddingCached($model);
            }
            return $scored;
        }

        $scored = TimingProfiler::profile('Ranking', $rank);
        $cache->set($key, $scored, self::RANKING_CACHE_TTL_SECONDS, CacheTag::dependency());

        return $scored;
    }

    /**
     * Fuse both raw signals, add boosts, and sort best-first.
     *
     * @param array<int, BoostHit> $boosts elementId => weight and the site whose rules matched
     * @return array<int, ScoredEntry>
     */
    public static function rank(array $semanticResults, array $keywordResults, array $boosts, Settings $settings): array
    {
        $semanticLookup = self::semanticLookup($semanticResults);
        $keywordLookup = self::keywordLookup($keywordResults);

        $scored = self::fuse($semanticLookup, $keywordLookup, $settings);

        Logger::debug('Search RRF', [
            'semanticRawRows' => count($semanticResults),
            'semanticUniqueElements' => count($semanticLookup),
            'keywordUniqueElements' => count($keywordLookup),
            'survived' => count($scored),
            'minSemanticThreshold' => $settings->minSemanticThreshold,
            'rrfSemanticWeight' => $settings->rrfSemanticWeight,
        ]);

        self::applyBoosts($scored, $boosts);

        uasort($scored, fn($a, $b) => $b['rrfScore'] <=> $a['rrfScore']);

        return $scored;
    }

    /**
     * Identity of every setting the ranking depends on, so a tuning change cannot be
     * served from a ranking cached under the old weights.
     */
    public static function settingsFingerprint(Settings $settings): string
    {
        return implode(',', [
            $settings->rrfSemanticWeight,
            $settings->minSemanticThreshold,
            $settings->maxSemanticResults,
        ]);
    }

    /**
     * Reciprocal Rank Fusion over the two ranked signal lookups.
     *
     * Per-signal contribution: weight / (RANK_OFFSET + rank). An entry is kept when its
     * meaning match reaches minSemanticThreshold or its word match covers most of the query.
     *
     * @param array<int, SignalEntry> $semanticLookup
     * @param array<int, SignalEntry> $keywordLookup
     * @return array<int, ScoredEntry>
     */
    private static function fuse(array $semanticLookup, array $keywordLookup, Settings $settings): array
    {
        $allIds = array_keys($semanticLookup + $keywordLookup);
        $keywordWeight = 1.0 - $settings->rrfSemanticWeight;

        $scored = [];
        foreach ($allIds as $id) {
            $hasSemantic = isset($semanticLookup[$id]);
            $hasKeyword = isset($keywordLookup[$id]);
            $semanticScore = $hasSemantic ? $semanticLookup[$id]['score'] : 0.0;

            $semanticPasses = $hasSemantic && $semanticScore >= $settings->minSemanticThreshold;
            $keywordPasses = $hasKeyword && $keywordLookup[$id]['coverage'] > self::MIN_KEYWORD_COVERAGE;
            if (!$semanticPasses && !$keywordPasses) {
                continue;
            }

            $rrfScore = 0.0;
            if ($hasSemantic) {
                $rrfScore += $settings->rrfSemanticWeight / (self::RANK_OFFSET + $semanticLookup[$id]['rank']);
            }
            if ($hasKeyword) {
                $rrfScore += $keywordWeight / (self::RANK_OFFSET + $keywordLookup[$id]['rank']);
            }

            if ($hasKeyword && $keywordLookup[$id]['score'] >= self::STRONG_KEYWORD_SCORE) {
                $rrfScore += $keywordWeight / self::RANK_OFFSET;
            }

            $chunk = $hasSemantic ? $semanticLookup[$id]['chunk'] : $keywordLookup[$id]['chunk'];

            $scored[$id] = [
                'rrfScore' => $rrfScore,
                'semanticScore' => $semanticScore,
                'semanticRank' => $hasSemantic ? $semanticLookup[$id]['rank'] : null,
                'keywordScore' => $hasKeyword ? $keywordLookup[$id]['score'] : 0.0,
                'keywordRank' => $hasKeyword ? $keywordLookup[$id]['rank'] : null,
                'chunk' => $chunk,
                'siteId' => $chunk[1],
            ];
        }

        return $scored;
    }

    /**
     * Add each matched rule's weight to the fused score, injecting entries that
     * matched only a boost. An injected entry belongs to the site whose rules matched.
     *
     * Scale note: rrfScore lands around 0.005-0.012, so any weight >= ~1 pins an entry
     * above every unboosted result and the weights only order boosted entries among
     * themselves.
     *
     * @param array<int, ScoredEntry> $scored
     * @param array<int, BoostHit> $boosts
     */
    private static function applyBoosts(array &$scored, array $boosts): void
    {
        foreach ($boosts as $elementId => ['weight' => $weight, 'siteId' => $siteId]) {
            if (isset($scored[$elementId])) {
                $scored[$elementId]['rrfScore'] += $weight;
            } else {
                $scored[$elementId] = [
                    'rrfScore' => $weight,
                    'semanticScore' => 0.0, 'semanticRank' => null,
                    'keywordScore' => 0.0, 'keywordRank' => null,
                    'chunk' => null,
                    'siteId' => $siteId,
                ];
            }
        }
    }

    /**
     * Index semantic results by elementId with rank and score for RRF lookup.
     *
     * @return array<int, SignalEntry>
     */
    private static function semanticLookup(array $semanticResults): array
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
     * Index keyword results by elementId with rank and score for RRF lookup.
     *
     * @return array<int, SignalEntry>
     */
    private static function keywordLookup(array $keywordResults): array
    {
        $lookup = [];
        $rank = 1;

        foreach ($keywordResults as $score) {
            if ($score['keywordScore'] > 0) {
                $lookup[$score['elementId']] = [
                    'score' => $score['keywordScore'],
                    'rank' => $rank++,
                    'chunk' => $score['chunk'],
                    'coverage' => $score['coverage'],
                ];
            }
        }

        return $lookup;
    }

    /**
     * Load Craft Entry elements for the top-scored results and build the
     * final response array with all score dimensions attached.
     *
     * Scoped to the searched site: without siteId() the query resolves against the
     * *requesting* site, so a search scoped to one site could hydrate another's rows.
     * Across all sites, each entry is loaded on the site it matched on (its best chunk's,
     * or for a boost-only hit the site whose rules matched), so an entry that only exists
     * on another site is kept and links to that site's page.
     *
     * @param array<int, ScoredEntry> $scoredResults Sorted best-first
     */
    public static function loadElements(array $scoredResults, int $limit, ?int $siteId = null): array
    {
        $allIds = array_keys($scoredResults);

        $missingCount = 0;
        $loadedCount = 0;
        $results = [];

        foreach (array_chunk($allIds, $limit + self::ELEMENT_WINDOW_SLACK) as $idBatch) {
            $bySite = [];
            foreach ($idBatch as $id) {
                $bySite[$siteId ?? $scoredResults[$id]['siteId']][] = $id;
            }

            $elements = [];
            foreach ($bySite as $site => $ids) {
                $elements += Entry::find()->id($ids)->siteId($site)->indexBy('id')->all();
            }
            $loadedCount += count($elements);

            foreach ($idBatch as $id) {
                if (!isset($elements[$id])) {
                    $missingCount++;
                    continue;
                }

                $element = $elements[$id];
                $data = $scoredResults[$id];

                $results[] = [
                    'element' => $element,
                    'url' => $element->getUrl(),
                    'score' => $data['rrfScore'],
                    'semanticScore' => $data['semanticScore'],
                    'semanticRank' => $data['semanticRank'],
                    'keywordScore' => $data['keywordScore'],
                    'keywordRank' => $data['keywordRank'],
                    'smartRank' => count($results) + 1,
                    'content' => '',
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
            'finalResults' => count($results),
            'limit' => $limit,
        ]);

        return $results;
    }

    /**
     * Fill each result's excerpt source from the fetched chunk text. A result whose chunk
     * is missing keeps an empty excerpt, so a failed fetch costs an excerpt rather than
     * a result.
     *
     * @param array<string, string> $bodies "elementId-siteId-chunkIndex" => chunk text
     */
    public static function attachChunkContent(array $finalResults, array $bodies): array
    {
        foreach ($finalResults as &$result) {
            if ($result['chunk'] !== null) {
                $result['content'] = $bodies[implode('-', $result['chunk'])] ?? '';
            }
        }
        unset($result);

        return $finalResults;
    }
}

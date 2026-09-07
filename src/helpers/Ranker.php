<?php

namespace ghoststreet\craftsmartsearch\helpers;

use Craft;
use craft\elements\Entry;
use ghoststreet\craftsmartsearch\models\Settings;

/**
 * Scoring shared by every search type: Reciprocal Rank Fusion over two ranked signals,
 * the boost merge, and the element hydration that turns a scored id map into results.
 *
 * Storage and signal generation differ per type; this does not. Both types call the same
 * code so a quality difference between them is attributable to their storage and scoring,
 * which is the only reason to run more than one.
 *
 * @phpstan-type ChunkKey array{0: int, 1: int, 2: int}
 * @phpstan-type SignalEntry array{score: float, rank: int, chunk?: ChunkKey, content?: string}
 * @phpstan-type ScoredEntry array{rrfScore: float, semanticScore: float, semanticRank: ?int, keywordScore: float, keywordRank: ?int, content: string, chunk: ?ChunkKey}
 */
final class Ranker
{
    /** Rank damping constant for Reciprocal Rank Fusion. */
    public const RANK_OFFSET = 60;

    /** Over-fetch multiplier to ensure enough unique entries after chunk deduplication */
    public const OVERFETCH_MULTIPLIER = 3;

    /** Minimum number of rows to fetch regardless of the requested limit */
    public const MIN_OVERFETCH = 20;

    /**
     * Spare candidates hydrated alongside each page of $limit, covering the few
     * dropped for being deleted or URL-less. If more than this are dropped, the
     * next window is fetched — correct either way, just one more query.
     */
    public const ELEMENT_WINDOW_SLACK = 5;

    /**
     * Identity of every setting the ranking depends on, so a tuning change cannot be
     * served from a ranking cached under the old weights.
     *
     * $extra distinguishes types that share these settings but not their storage.
     */
    public static function settingsFingerprint(Settings $settings, string $extra = ''): string
    {
        return implode(',', [
            $settings->rrfSemanticWeight,
            $settings->rrfKeywordWeight,
            $settings->minSemanticThreshold,
            $settings->maxSemanticResults,
            $settings->embeddingModel,
            $settings->enableTypoTolerance ? '1' : '0',
            $extra,
        ]);
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
    public static function fuse(array $semanticLookup, array $keywordLookup, Settings $settings): array
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
                   key later; only a keyword-only hit already carries its text. Returning
                   keys for keyword hits too was measured twice and lost both times on the
                   pgvector path: it moves cost out of a covered phase into the uncovered
                   chunk fetch. So the keyword key is used only when the signal actually
                   supplied one, which the local type does and the pgvector one does not. */
                'chunk' => $hasSemantic
                    ? $semanticLookup[$id]['chunk']
                    : ($keywordLookup[$id]['chunk'] ?? null),
                'content' => $hasSemantic ? '' : ($keywordLookup[$id]['content'] ?? ''),
            ];
        }

        return $scored;
    }

    /**
     * Add each matched rule's weight to the fused score, injecting entries that
     * matched only a boost.
     *
     * Scale note: rrfScore lands around 0.005-0.012, so any weight >= ~1 pins an entry
     * above every unboosted result and the weights only order boosted entries among
     * themselves.
     *
     * @param array<int, ScoredEntry> $scored
     * @param array<int, float> $boosts elementId => summed weight
     */
    public static function applyBoosts(array &$scored, array $boosts): void
    {
        foreach ($boosts as $elementId => $weight) {
            if (isset($scored[$elementId])) {
                $scored[$elementId]['rrfScore'] += $weight;
            } else {
                $scored[$elementId] = [
                    'rrfScore' => $weight,
                    'semanticScore' => 0.0, 'semanticRank' => 0,
                    'keywordScore' => 0.0, 'keywordRank' => 0,
                    'content' => '',
                    'chunk' => null,
                ];
            }
        }
    }

    /**
     * Index semantic results by elementId with rank and score for RRF lookup.
     *
     * @return array<int, SignalEntry>
     */
    public static function semanticLookup(array $semanticResults): array
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
    public static function keywordLookup(array $keywordResults): array
    {
        $lookup = [];
        $rank = 1;

        foreach ($keywordResults as $score) {
            if ($score['keywordScore'] > 0) {
                $lookup[$score['elementId']] = [
                    'score' => $score['keywordScore'],
                    'rank' => $rank++,
                    'content' => $score['content'] ?? '',
                    /* Only signals that score per chunk supply this. */
                    'chunk' => $score['chunk'] ?? null,
                ];
            }
        }

        return $lookup;
    }

    /**
     * Resolve section handles to ids. Unknown handles are dropped, so an
     * empty return means the filter cannot match anything.
     *
     * @param string[] $handles
     * @return int[]
     */
    public static function resolveSectionIds(array $handles): array
    {
        return array_values(array_filter(array_map(
            static fn(string $h) => Craft::$app->getEntries()->getSectionByHandle($h)?->id,
            $handles,
        )));
    }

    /**
     * Load Craft Entry elements for the top-scored results and build the
     * final response array with all score dimensions attached.
     *
     * Scoped to the searched site: without siteId() the query resolves against the
     * *requesting* site, so a search scoped to one site could hydrate another's rows.
     *
     * @param array<int, ScoredEntry> $scoredResults Sorted best-first
     */
    public static function loadElements(array $scoredResults, int $limit, ?int $siteId = null): array
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
            $query = Entry::find()->id($idBatch)->indexBy('id');
            if ($siteId !== null) {
                $query->siteId($siteId);
            }
            $elements = $query->all();
            $loadedCount += count($elements);

            foreach ($idBatch as $id) {
                if (!isset($elements[$id])) {
                    $missingCount++;
                    continue;
                }

                $element = $elements[$id];
                /* getUrl() fires the define-url events every call, so keep the one we
                   already computed and hand it to the formatter. */
                $url = $element->getUrl();
                if ($url === null) {
                    $noUrlCount++;
                    continue;
                }

                $data = $scoredResults[$id];

                $results[] = [
                    'element' => $element,
                    'url' => $url,
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

    /**
     * Fill each result's excerpt source from the fetched chunk text. A result whose chunk
     * is missing keeps the content it already had, so a failed fetch costs an excerpt
     * rather than a result.
     */
    public static function attachChunkContent(array $finalResults, ?callable $contentPrefetch): array
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
}

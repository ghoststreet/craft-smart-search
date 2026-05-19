<?php

namespace ghoststreet\craftaisearch\services;

/**
 * Pure Reciprocal Rank Fusion math.
 *
 * Takes two ranked signal lookups (semantic, BM25) keyed by elementId and
 * returns a single map of fused scores per element. No I/O, no Craft, no
 * settings object — every parameter is explicit.
 *
 * Per-signal score: weight / (k + rank).
 * Elements appearing in only one signal are multiplied by singleSignalPenalty.
 * Elements appearing only in semantic results must exceed minSemanticThreshold
 * to be kept; otherwise dropped.
 *
 * @phpstan-type SignalEntry array{score: float, rank: int, content: string}
 * @phpstan-type ScoredEntry array{rrfScore: float, semanticScore: float, semanticRank: ?int, bm25Score: float, bm25Rank: ?int, content: string}
 */
final class RrfFuser
{
    /**
     * @param array<int, SignalEntry> $semanticLookup
     * @param array<int, SignalEntry> $bm25Lookup
     * @return array<int, ScoredEntry>
     */
    public function fuse(
        array $semanticLookup,
        array $bm25Lookup,
        int $k,
        float $semanticWeight,
        float $bm25Weight,
        float $singleSignalPenalty,
        float $minSemanticThreshold,
    ): array {
        $allIds = array_unique([...array_keys($semanticLookup), ...array_keys($bm25Lookup)]);

        $scored = [];
        foreach ($allIds as $id) {
            $hasSemantic = isset($semanticLookup[$id]);
            $hasBm25 = isset($bm25Lookup[$id]);
            $semanticScore = $hasSemantic ? $semanticLookup[$id]['score'] : 0.0;

            if ($hasSemantic && !$hasBm25 && $semanticScore < $minSemanticThreshold) {
                continue;
            }

            $isSingleSignal = !($hasSemantic && $hasBm25);
            $rrfScore = 0.0;

            if ($hasSemantic) {
                $rrfScore += $semanticWeight / ($k + $semanticLookup[$id]['rank']);
            }
            if ($hasBm25) {
                $rrfScore += $bm25Weight / ($k + $bm25Lookup[$id]['rank']);
            }

            if ($isSingleSignal) {
                $rrfScore *= $singleSignalPenalty;
            }

            $scored[$id] = [
                'rrfScore' => $rrfScore,
                'semanticScore' => $semanticScore,
                'semanticRank' => $hasSemantic ? $semanticLookup[$id]['rank'] : null,
                'bm25Score' => $hasBm25 ? $bm25Lookup[$id]['score'] : 0.0,
                'bm25Rank' => $hasBm25 ? $bm25Lookup[$id]['rank'] : null,
                'content' => $hasSemantic
                    ? $semanticLookup[$id]['content']
                    : ($hasBm25 ? ($bm25Lookup[$id]['content'] ?? '') : ''),
            ];
        }

        return $scored;
    }
}

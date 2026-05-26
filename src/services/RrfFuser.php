<?php

namespace ghoststreet\craftsmartsearch\services;

/**
 * Pure Reciprocal Rank Fusion math.
 *
 * Takes two ranked signal lookups (semantic, keyword) keyed by elementId and
 * returns a single map of fused scores per element. No I/O, no Craft, no
 * settings object — every parameter is explicit.
 *
 * Per-signal score: weight / (k + rank).
 * Elements appearing in only one signal are multiplied by singleSignalPenalty.
 * Any element whose semantic score is below minSemanticThreshold is dropped,
 * regardless of whether the keyword signal also matched — a weak vector match
 * with a loose keyword hit is still likely a false positive.
 *
 * @phpstan-type SignalEntry array{score: float, rank: int, content: string}
 * @phpstan-type ScoredEntry array{rrfScore: float, semanticScore: float, semanticRank: ?int, keywordScore: float, keywordRank: ?int, content: string}
 */
final class RrfFuser
{
    public const RANK_OFFSET = 60;

    /**
     * @param array<int, SignalEntry> $semanticLookup
     * @param array<int, SignalEntry> $keywordLookup
     * @return array<int, ScoredEntry>
     */
    public function fuse(
        array $semanticLookup,
        array $keywordLookup,
        float $semanticWeight,
        float $keywordWeight,
        float $singleSignalPenalty,
        float $minSemanticThreshold,
    ): array {
        $allIds = array_unique([...array_keys($semanticLookup), ...array_keys($keywordLookup)]);

        $scored = [];
        foreach ($allIds as $id) {
            $hasSemantic = isset($semanticLookup[$id]);
            $hasKeyword = isset($keywordLookup[$id]);
            $semanticScore = $hasSemantic ? $semanticLookup[$id]['score'] : 0.0;

            if ($hasSemantic && $semanticScore < $minSemanticThreshold) {
                continue;
            }

            $isSingleSignal = !($hasSemantic && $hasKeyword);
            $rrfScore = 0.0;

            if ($hasSemantic) {
                $rrfScore += $semanticWeight / (self::RANK_OFFSET + $semanticLookup[$id]['rank']);
            }
            if ($hasKeyword) {
                $rrfScore += $keywordWeight / (self::RANK_OFFSET + $keywordLookup[$id]['rank']);
            }

            if ($isSingleSignal) {
                $rrfScore *= $singleSignalPenalty;
            }

            $scored[$id] = [
                'rrfScore' => $rrfScore,
                'semanticScore' => $semanticScore,
                'semanticRank' => $hasSemantic ? $semanticLookup[$id]['rank'] : null,
                'keywordScore' => $hasKeyword ? $keywordLookup[$id]['score'] : 0.0,
                'keywordRank' => $hasKeyword ? $keywordLookup[$id]['rank'] : null,
                'content' => $hasSemantic
                    ? $semanticLookup[$id]['content']
                    : ($hasKeyword ? ($keywordLookup[$id]['content'] ?? '') : ''),
            ];
        }

        return $scored;
    }
}

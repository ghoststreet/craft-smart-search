<?php

namespace ghoststreet\craftsmartsearch\services;

/**
 * Reciprocal Rank Fusion over two ranked signal lookups (semantic, keyword).
 *
 * Per-signal contribution: weight / (k + rank). Entries below
 * minSemanticThreshold that lack a keyword hit are dropped as semantic noise.
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
        float $minSemanticThreshold,
    ): array {
        $allIds = array_unique([...array_keys($semanticLookup), ...array_keys($keywordLookup)]);

        $scored = [];
        foreach ($allIds as $id) {
            $hasSemantic = isset($semanticLookup[$id]);
            $hasKeyword = isset($keywordLookup[$id]);
            $semanticScore = $hasSemantic ? $semanticLookup[$id]['score'] : 0.0;

            if ($hasSemantic && !$hasKeyword && $semanticScore < $minSemanticThreshold) {
                continue;
            }

            $rrfScore = 0.0;
            if ($hasSemantic) {
                $rrfScore += $semanticWeight / (self::RANK_OFFSET + $semanticLookup[$id]['rank']);
            }
            if ($hasKeyword) {
                $rrfScore += $keywordWeight / (self::RANK_OFFSET + $keywordLookup[$id]['rank']);
            }

            if ($hasKeyword && $keywordLookup[$id]['score'] >= 0.5) {
                $rrfScore += $keywordWeight / self::RANK_OFFSET;
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

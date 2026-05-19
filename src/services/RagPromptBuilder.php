<?php

namespace ghoststreet\craftaisearch\services;

use ghoststreet\craftaisearch\helpers\TextValidator;
use ghoststreet\craftaisearch\helpers\TokenEstimator;

/**
 * Pure prompt-assembly math for RAG.
 *
 * Two responsibilities, both side-effect free:
 *   1. computeContextBudget()  — how many tokens are left for source blocks
 *                                after reserving room for system/query/output.
 *   2. buildContext()          — pack as many source blocks as fit into the
 *                                budget, in priority order.
 *
 * Source rows are accepted as plain arrays — no Entry coupling — so unit tests
 * can construct realistic fixtures without spinning up Craft elements.
 *
 * @phpstan-type SourceRow array{id: int|string, title: string, url: string, content: string}
 */
final class RagPromptBuilder
{
    public const SYSTEM_RESERVE_TOKENS = 1200;
    public const QUERY_PADDING_TOKENS = 64;
    public const OUTPUT_RESERVE_TOKENS = 800;
    public const MIN_CONTEXT_BUDGET = 500;

    /**
     * Tokens left for source blocks after reserving room for the system prompt,
     * the user query, and the LLM output. Never goes below MIN_CONTEXT_BUDGET so
     * callers always have something to pack — pointless RAG calls are avoided
     * by guarding upstream, not by silently returning 0 here.
     */
    public function computeContextBudget(int $maxPromptTokens, string $query): int
    {
        $queryReserve = TokenEstimator::estimateTokens($query) + self::QUERY_PADDING_TOKENS;
        $budget = $maxPromptTokens - self::SYSTEM_RESERVE_TOKENS - $queryReserve - self::OUTPUT_RESERVE_TOKENS;
        return max(self::MIN_CONTEXT_BUDGET, $budget);
    }

    /**
     * Pack source rows into a context string until $tokenBudget is exhausted.
     *
     * The first source is always included even if it alone exceeds the budget —
     * a single oversized source is a fixture problem, not a runtime branch we
     * want to handle by emitting an empty prompt.
     *
     * @param list<SourceRow> $sources
     * @return array{context: string, includedCount: int, usedTokens: int, droppedAtIndex: ?int}
     */
    public function buildContext(array $sources, int $tokenBudget): array
    {
        $blocks = [];
        $used = 0;
        $droppedAt = null;

        foreach ($sources as $i => $src) {
            $content = TextValidator::sanitizeQuery((string)$src['content']);
            $block = "---\nOUR PAGE {$src['id']}\nTitle: {$src['title']}\nURL: {$src['url']}\nContent:\n{$content}\n---";
            $blockTokens = TokenEstimator::estimateTokens($block);

            if ($blocks !== [] && $used + $blockTokens > $tokenBudget) {
                $droppedAt = $i;
                break;
            }

            $blocks[] = $block;
            $used += $blockTokens;
        }

        return [
            'context' => implode("\n\n", $blocks),
            'includedCount' => count($blocks),
            'usedTokens' => $used,
            'droppedAtIndex' => $droppedAt,
        ];
    }
}

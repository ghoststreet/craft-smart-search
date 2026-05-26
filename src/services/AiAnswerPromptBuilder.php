<?php

namespace ghoststreet\craftsmartsearch\services;

use ghoststreet\craftsmartsearch\helpers\TextValidator;
use ghoststreet\craftsmartsearch\helpers\TokenEstimator;

/**
 * Pure prompt-assembly math for AI Answer.
 *
 * Two responsibilities, both side-effect free:
 *   1. computeContextBudget()  the cap on tokens packed into source blocks.
 *      This is what the admin sets in Settings; the model's own context
 *      window absorbs the system prompt, query, and output on top.
 *   2. buildContext()          pack as many source blocks as fit into the
 *                              budget, in priority order.
 *
 * Source rows are accepted as plain arrays (no Entry coupling) so unit tests
 * can construct realistic fixtures without spinning up Craft elements.
 *
 * @phpstan-type SourceRow array{id: int|string, title: string, url: string, content: string}
 */
final class AiAnswerPromptBuilder
{
    public const MIN_CONTEXT_BUDGET = 500;

    /**
     * Token budget for source content. The validator on maxPromptTokens already
     * enforces 500 as the minimum; this floor is just a defensive safety net.
     */
    public function computeContextBudget(int $maxPromptTokens): int
    {
        return max(self::MIN_CONTEXT_BUDGET, $maxPromptTokens);
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
            $title = str_replace(["\r", "\n"], ' ', (string)$src['title']);
            $url = str_replace(["\r", "\n"], '', (string)$src['url']);
            $block = "---\nOUR PAGE {$src['id']}\nTitle: {$title}\nURL: {$url}\nContent:\n{$content}\n---";
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

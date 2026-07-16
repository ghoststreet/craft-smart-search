<?php

namespace ghoststreet\craftsmartsearch\services;

use ghoststreet\craftsmartsearch\helpers\TextValidator;
use ghoststreet\craftsmartsearch\helpers\TokenEstimator;

/**
 * Pure prompt-assembly math for AI Answer: pack as many source blocks as fit
 * into the token budget, in priority order. Side-effect free.
 *
 * Source rows are accepted as plain arrays (no Entry coupling) so unit tests
 * can construct realistic fixtures without spinning up Craft elements.
 *
 * @phpstan-type SourceRow array{id: int|string, title: string, url: string, content: string}
 */
final class AiAnswerPromptBuilder
{
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

<?php

namespace ghoststreet\craftsmartsearch\models;

/**
 * Immutable value object describing one search to be recorded in history.
 *
 * Priced when it is built (SearchRunner::historyEntry()), since the usage it is priced
 * from belongs to the search that just ran.
 */
final class SearchHistoryEntry
{
    public function __construct(
        public readonly ?string $requestId,
        public readonly string $type,
        public readonly string $query,
        public readonly ?int $siteId,
        public readonly int $durationMs,
        public readonly int $resultsCount,
        public readonly int $embeddingTokens,
        public readonly int $aiAnswerInputTokens,
        public readonly int $aiAnswerOutputTokens,
        public readonly bool $embeddingCached,
        public readonly ?string $embeddingModel,
        public readonly ?string $aiAnswerModel,
        public readonly float $cost,
        public readonly ?string $errorMessage = null,
    ) {
    }
}

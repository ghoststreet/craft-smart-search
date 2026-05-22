<?php

namespace ghoststreet\craftsmartsearch\models;

/**
 * Immutable value object describing one search to be recorded in history.
 *
 * Carries only the raw inputs the controller knows about — derived columns
 * (totalTokens, cost) are computed by HistoryService when the row is persisted.
 */
final class SearchHistoryEntry
{
    public function __construct(
        public readonly string $requestId,
        public readonly string $type,
        public readonly string $query,
        public readonly ?int $userId,
        public readonly ?int $siteId,
        public readonly int $durationMs,
        public readonly int $resultsCount,
        public readonly int $embeddingTokens,
        public readonly int $ragInputTokens,
        public readonly int $ragOutputTokens,
        public readonly bool $embeddingCached,
        public readonly ?string $embeddingModel,
        public readonly ?string $ragModel,
        public readonly ?string $errorMessage = null,
    ) {
    }
}

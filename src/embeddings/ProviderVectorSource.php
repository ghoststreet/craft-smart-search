<?php

namespace ghoststreet\craftsmartsearch\embeddings;

use ghoststreet\craftsmartsearch\SmartSearch;

/**
 * Vectors from the configured embeddings API.
 *
 * The transport, caching, usage accounting and error taxonomy stay in EmbeddingService; what
 * lives here is whether this source is usable and what it stamps.
 */
class ProviderVectorSource implements VectorSource
{
    public static function isAvailable(): bool
    {
        return SmartSearch::getInstance()->getSettings()->hasProviderKey();
    }

    /** The model, not the vendor: two models from one provider are two embedding spaces. */
    public function handle(): string
    {
        return SmartSearch::getInstance()->getSettings()->embeddingModel;
    }

    public function label(): string
    {
        return SmartSearch::getInstance()->getSettings()->provider()::label() . ' embeddings';
    }

    public function siteScoped(): bool
    {
        return false;
    }

    public function dimensions(?int $siteId = null): int
    {
        return SmartSearch::getInstance()->getSettings()->dimensions;
    }

    public function dispatch(string $text, ?int $siteId = null): callable
    {
        return SmartSearch::getInstance()->embeddingService->dispatchEmbedding($text);
    }

    public function generate(string $text, ?int $siteId = null): array
    {
        return SmartSearch::getInstance()->embeddingService->generateEmbedding($text);
    }
}

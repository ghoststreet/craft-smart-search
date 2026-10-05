<?php

namespace ghoststreet\craftsmartsearch\embeddings;

use ghoststreet\craftsmartsearch\engines\local\LocalContextVectors;
use ghoststreet\craftsmartsearch\SmartSearch;

/**
 * Vectors built from the site's own content, with no API. The model is LocalContextVectors';
 * this is the adapter that lets it stand in the same slot as a paid embeddings API.
 *
 * Always available, because it is the fallback. dimensions() reports 0 until the corpus has
 * been modelled, so callers can tell "no vectors expected" from "vectors missing".
 *
 * The model is learned from the Standard engine's inverted index.
 */
class LocalVectorSource implements VectorSource
{
    public static function isAvailable(): bool
    {
        return true;
    }

    public function handle(): string
    {
        return LocalContextVectors::HANDLE;
    }

    public function label(): string
    {
        return LocalContextVectors::LABEL;
    }

    /** Each site's term vectors are seeded and learned from that site's corpus alone. */
    public function siteScoped(): bool
    {
        return true;
    }

    public function dimensions(?int $siteId = null): int
    {
        if (!LocalContextVectors::instance()->isModelled($siteId)) {
            return 0;
        }

        return SmartSearch::getInstance()->getSettings()->dimensions;
    }

    public function dispatch(string $text, ?int $siteId = null): callable
    {
        $vector = $this->generate($text, $siteId);

        return static fn(): array => $vector;
    }

    public function generate(string $text, ?int $siteId = null): array
    {
        if (!LocalContextVectors::instance()->isModelled($siteId)) {
            return [];
        }

        return LocalContextVectors::instance()->vectorFor($text, $siteId);
    }
}

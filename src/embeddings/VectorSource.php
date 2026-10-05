<?php

namespace ghoststreet\craftsmartsearch\embeddings;

/**
 * Where a vector comes from.
 *
 * The engines decide how vectors are stored and scanned; this decides what produces them. Two
 * independent axes, which is why this is not a method on SearchEngine.
 *
 * A source stamps its handle into the vectors table's model column, which already carries the
 * rule that two models at the same width are two different embedding spaces. That column is
 * what makes switching sources safe with no migration and no new state.
 *
 * Adding a source is a new file plus one line in VectorSourceRegistry.
 */
interface VectorSource
{
    public static function isAvailable(): bool;

    /** Stamped on every vector this source produces. */
    public function handle(): string;

    public function label(): string;

    /**
     * Whether each site has its own vector space. A vector from such a source only means
     * something against vectors built for the same site, so a search across every site
     * needs one query vector per site.
     */
    public function siteScoped(): bool;

    /** 0 when this source cannot produce anything for this site right now, which is valid. */
    public function dimensions(?int $siteId = null): int;

    /**
     * Write the request now, read the reply later, so callers can arrange their work the same
     * way whether or not the source has a real wait to overlap.
     *
     * @return callable(): float[]
     */
    public function dispatch(string $text, ?int $siteId = null): callable;

    /** @return float[] Empty when this source has nothing to say about the text. */
    public function generate(string $text, ?int $siteId = null): array;
}

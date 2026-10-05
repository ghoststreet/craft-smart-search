<?php

namespace ghoststreet\craftsmartsearch\engines\local;

use craft\db\Query;

/**
 * The Standard engine's table names, and the one way to walk them.
 *
 * Each engine owns the names of its own store. Keeping them here rather than on the
 * install migration means the engine can be read without knowing how its tables came to
 * exist, and means two engines can never end up sharing a constant name that resolves to
 * different tables.
 *
 * The physical names carry a `_local_` segment because a vector-extension engine's own
 * `smart_search_vectors`, `_terms` and `_boosts` tables would otherwise collide with them.
 */
final class LocalSchema
{
    public const CHUNKS_TABLE = '{{%smart_search_local_chunks}}';

    public const VECTORS_TABLE = '{{%smart_search_local_vectors}}';

    public const POSTINGS_TABLE = '{{%smart_search_local_postings}}';

    public const TERMS_TABLE = '{{%smart_search_local_terms}}';

    public const BOOSTS_TABLE = '{{%smart_search_local_boosts}}';

    /** Every table this engine owns, in drop-safe order. */
    public const ALL = [
        self::BOOSTS_TABLE,
        self::TERMS_TABLE,
        self::POSTINGS_TABLE,
        self::VECTORS_TABLE,
        self::CHUNKS_TABLE,
    ];

    /**
     * Keyset pages over a query on one of these tables, by ascending id. The query must
     * select `id`. Keyset rather than OFFSET so the last page costs what the first did.
     *
     * @return iterable<list<array<string, mixed>>>
     */
    public static function batches(Query $query, int $size): iterable
    {
        $lastId = 0;

        while (true) {
            $rows = (clone $query)
                ->andWhere(['>', 'id', $lastId])
                ->orderBy(['id' => SORT_ASC])
                ->limit($size)
                ->all();

            if ($rows === []) {
                return;
            }

            yield $rows;

            $lastId = (int)$rows[count($rows) - 1]['id'];
        }
    }
}

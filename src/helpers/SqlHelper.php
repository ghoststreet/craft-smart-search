<?php

namespace ghoststreet\craftsmartsearch\helpers;

/**
 * Small builders for raw PDO SQL fragments used by the pgvector query paths,
 * which bind parameters by hand because pgvector operators need raw SQL.
 */
final class SqlHelper
{
    /**
     * Build a parameterized `(:prefix0, :prefix1, ...)` list for an IN clause.
     *
     * @param scalar[] $values
     * @return array{0: string, 1: array<string, scalar>} The placeholder list and its bind params
     */
    public static function namedInList(array $values, string $prefix): array
    {
        $placeholders = [];
        $params = [];

        foreach (array_values($values) as $i => $value) {
            $key = ":{$prefix}{$i}";
            $placeholders[] = $key;
            $params[$key] = $value;
        }

        return ['(' . implode(', ', $placeholders) . ')', $params];
    }
}

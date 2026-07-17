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

    /**
     * Rewrite a PDO named-parameter query into the positional form ext-pgsql wants
     * (`:query` → `$1`), returning the SQL plus values ordered to match.
     *
     * A name mentioned twice claims two placeholders and is sent twice.
     *
     * @param array<string, scalar|null> $named Keys with or without the leading colon
     * @return array{0: string, 1: list<scalar|null>}
     */
    public static function toPositional(string $sql, array $named): array
    {
        $byName = [];
        foreach ($named as $key => $value) {
            $byName[ltrim((string)$key, ':')] = $value;
        }

        $values = [];
        $rewritten = preg_replace_callback(
            '/:([a-zA-Z_][a-zA-Z0-9_]*)/',
            static function(array $m) use ($byName, &$values): string {
                if (!array_key_exists($m[1], $byName)) {
                    return $m[0];
                }
                $values[] = $byName[$m[1]];
                return '$' . count($values);
            },
            $sql,
        );

        return [$rewritten, $values];
    }
}

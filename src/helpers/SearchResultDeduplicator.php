<?php

namespace ghoststreet\craftsmartsearch\helpers;

/**
 * Helper for deduplicating search results.
 * Entries can have multiple chunks, so we need to keep only the highest-scoring result per entry.
 */
final class SearchResultDeduplicator
{
    /**
     * Deduplicate search results by element ID, keeping the highest scoring result.
     *
     * @param array<int, array{elementId: int, siteId: int, content: string, ...}> $results Raw search result rows
     * @param string $scoreKey The array key containing the numeric score (e.g. 'similarity')
     * @return array<int, array{elementId: int, siteId: int, content: string, ...}> Deduplicated results
     */
    private static function deduplicateByElement(array $results, string $scoreKey = 'similarity'): array
    {
        $deduplicated = [];

        foreach ($results as $result) {
            $key = $result['elementId'] . '-' . $result['siteId'];

            if (!isset($deduplicated[$key]) ||
                (float)$result[$scoreKey] > (float)$deduplicated[$key][$scoreKey]) {
                $deduplicated[$key] = $result;
            }
        }

        return array_values($deduplicated);
    }

    /**
     * Sort results by score descending and apply limit.
     *
     * @param array<int, array<string, mixed>> $results Result rows containing numeric scores
     * @param string $scoreKey The array key containing the numeric score to sort by
     * @param int $limit Maximum number of results to return
     * @return array<int, array<string, mixed>> Sorted and limited results
     */
    private static function sortAndLimit(array $results, string $scoreKey, int $limit): array
    {
        usort($results, function($a, $b) use ($scoreKey) {
            return $b[$scoreKey] <=> $a[$scoreKey];
        });

        return array_slice($results, 0, $limit);
    }

    /**
     * Deduplicate, sort, and limit results in one operation.
     *
     * @param array<int, array{elementId: int, siteId: int, content: string, ...}> $results Raw search result rows
     * @param string $scoreKey The array key containing the numeric score
     * @param int $limit Maximum number of results to return
     * @return array<int, array{elementId: int, siteId: int, content: string, ...}> Deduplicated, sorted, and limited results
     */
    public static function process(array $results, string $scoreKey, int $limit): array
    {
        $deduplicated = self::deduplicateByElement($results, $scoreKey);

        Logger::debug('SearchResultDeduplicator', [
            'scoreKey' => $scoreKey,
            'rowsIn' => count($results),
            'uniqueElements' => count($deduplicated),
            'collapsedChunks' => count($results) - count($deduplicated),
            'limit' => $limit,
        ]);

        return self::sortAndLimit($deduplicated, $scoreKey, $limit);
    }
}

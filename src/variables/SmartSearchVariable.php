<?php

namespace ghoststreet\craftsmartsearch\variables;

use ghoststreet\craftsmartsearch\helpers\RequestParameterExtractor;
use ghoststreet\craftsmartsearch\SmartSearch;

/**
 * Twig variable class for Smart Search.
 *
 * Provides `craft.smartSearch.search()` and `craft.smartSearch.aiAnswer()` for frontend templates.
 */
class SmartSearchVariable
{
    /**
     * Perform a smart search (semantic + keyword) search from Twig templates.
     *
     * @param string $query The search query
     * @param int $limit Maximum number of results
     * @param int|null $siteId Optional site ID filter
     * @param array|string|null $sections Optional section handles (array or CSV string)
     * @return array Search results with element, score, and content
     */
    public function search(string $query, int $limit = 10, ?int $siteId = null, array|string|null $sections = null): array
    {
        return SmartSearch::getInstance()->smartSearchService->search(
            $query,
            $limit,
            $siteId,
            sections: RequestParameterExtractor::normalizeSections($sections),
        );
    }

    /**
     * Perform a AI Answer search from Twig templates.
     *
     * @param string $query The search query
     * @param int $limit Maximum number of source entries
     * @param int|null $siteId Optional site ID filter
     * @return array AI Answer response with summary, sources, and confidence
     */
    public function aiAnswer(string $query, int $limit = 5, ?int $siteId = null): array
    {
        return SmartSearch::getInstance()->aiAnswerService->search($query, $limit, $siteId);
    }
}

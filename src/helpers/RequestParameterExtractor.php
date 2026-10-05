<?php

namespace ghoststreet\craftsmartsearch\helpers;

use Craft;
use ghoststreet\craftsmartsearch\exceptions\SearchException;
use ghoststreet\craftsmartsearch\services\SearchRunner;

/**
 * Helper for extracting and validating common request parameters.
 *
 * Consolidates the duplicated parameter extraction pattern used
 * across search controller action methods.
 */
final class RequestParameterExtractor
{
    /**
     * Extract and validate common search parameters from the current request.
     *
     * siteId semantics:
     *   - Single-site install: param optional; resolves to that site's id.
     *   - Multi-site install: param required. `0` means "all sites" (returns null
     *     internally so service-layer WHERE filters are skipped). Any other value
     *     must match an existing site id.
     *
     * @param int $defaultLimit Default result limit if not specified in request
     * @return array{query: string, limit: int, siteId: int|null, sections: string[], sectionIds: list<int>|null, validationError: array|null}
     */
    public static function extractSearchParams(int $defaultLimit = SearchRunner::DEFAULT_SEARCH_LIMIT): array
    {
        $request = Craft::$app->getRequest();

        try {
            $query = SearchRunner::query((string)$request->getParam('q', ''));
        } catch (SearchException) {
            $query = '';
        }

        $limit = ApiResponseHelper::clampLimit(
            (int)$request->getParam('limit', $defaultLimit)
        );

        $sections = self::normalizeSections($request->getParam('sections'));
        [$siteId, $siteValid] = self::resolveSiteId($request->getParam('siteId'));

        return [
            'query' => $query,
            'limit' => $limit,
            'siteId' => $siteId,
            'sections' => $sections,
            'sectionIds' => $sections === [] ? null : self::sectionIds($sections),
            'validationError' => $query === '' || !$siteValid ? ApiResponseHelper::validationErrorBody() : null,
        ];
    }

    /**
     * Split a CSV string of section handles into an array; arrays pass through.
     * Anything else means no filter.
     *
     * @return string[]
     */
    public static function normalizeSections(mixed $raw): array
    {
        if (is_string($raw)) {
            return array_values(array_filter(array_map('trim', explode(',', $raw))));
        }

        return is_array($raw) ? $raw : [];
    }

    /**
     * Resolve section handles to ids. Unknown handles are dropped, so an
     * empty return means the filter cannot match anything.
     *
     * @param string[] $handles
     * @return list<int>
     */
    public static function sectionIds(array $handles): array
    {
        return array_values(array_filter(array_map(
            static fn(string $h) => Craft::$app->getEntries()->getSectionByHandle($h)?->id,
            $handles,
        )));
    }

    /**
     * A caller-supplied siteId, checked against the install's sites. `0` means every site
     * (null) and, on a single-site install, so does a missing one, which resolves to that
     * site. Anything else must be an existing site.
     *
     * @return array{0: int|null, 1: bool} the site id, and whether the value was acceptable
     */
    public static function resolveSiteId(mixed $rawSiteId): array
    {
        $allSites = Craft::$app->getSites()->getAllSites();
        $siteId = (int)$rawSiteId;

        if (count($allSites) === 1) {
            $onlySiteId = (int)$allSites[0]->id;
            if ($siteId === 0 || $siteId === $onlySiteId) {
                return [$onlySiteId, true];
            }
        } elseif ($rawSiteId !== null && $rawSiteId !== '') {
            if ($siteId === 0) {
                return [null, true];
            }
            if (Craft::$app->getSites()->getSiteById($siteId) !== null) {
                return [$siteId, true];
            }
        }

        return [null, false];
    }
}

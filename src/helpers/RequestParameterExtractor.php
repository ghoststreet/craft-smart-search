<?php

namespace ghoststreet\craftsmartsearch\helpers;

use Craft;

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
     * @return array{query: string, limit: int, siteId: int|null, sections: string[], validationError: array|null}
     */
    public static function extractSearchParams(int $defaultLimit = 10): array
    {
        $request = Craft::$app->getRequest();

        $rawQuery = (string)$request->getParam('q', '');
        $query = TextValidator::sanitizeQuery($rawQuery);
        $limit = ApiResponseHelper::clampLimit(
            (int)$request->getParam('limit', $defaultLimit)
        );

        $sections = self::normalizeSections($request->getParam('sections'));

        $rawSiteId = $request->getParam('siteId');
        $siteResolution = self::resolveSiteId($rawSiteId);

        $queryInvalid = TextValidator::isEmpty($query)
            || mb_strlen($query) > ApiResponseHelper::MAX_QUERY_LENGTH;

        $validationError = $queryInvalid
            ? ApiResponseHelper::validationErrorBody()
            : $siteResolution['validationError'];

        return [
            'query' => $query,
            'limit' => $limit,
            'siteId' => $siteResolution['siteId'],
            'sections' => $sections,
            'validationError' => $validationError,
        ];
    }

    /**
     * Split a CSV string of section handles into an array; arrays pass through.
     * Anything else means no filter. Unknown handles simply match no entries.
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
     * @return array{siteId: int|null, validationError: array|null}
     */
    private static function resolveSiteId(mixed $rawSiteId): array
    {
        $allSites = Craft::$app->getSites()->getAllSites();
        $siteId = (int)$rawSiteId;

        if (count($allSites) <= 1) {
            $onlySiteId = $allSites === [] ? null : (int)$allSites[0]->id;
            if ($siteId === 0 || $siteId === $onlySiteId) {
                return ['siteId' => $onlySiteId, 'validationError' => null];
            }
        } elseif ($rawSiteId !== null && $rawSiteId !== '') {
            $knownIds = array_map(static fn($site): int => (int)$site->id, $allSites);
            if ($siteId === 0 || in_array($siteId, $knownIds, true)) {
                return ['siteId' => $siteId ?: null, 'validationError' => null];
            }
        }

        return ['siteId' => null, 'validationError' => ApiResponseHelper::validationErrorBody()];
    }
}

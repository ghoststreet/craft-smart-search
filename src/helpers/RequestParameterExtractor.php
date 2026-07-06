<?php

namespace ghoststreet\craftsmartsearch\helpers;

use Craft;
use ghoststreet\craftsmartsearch\exceptions\ErrorCode;

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
     * @return array{query: string, limit: int, siteId: int|null, allSites: bool, sections: string[], validationError: array|null}
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

        $validationError = ApiResponseHelper::validateQuery($query)
            ?? $siteResolution['validationError'];

        return [
            'query' => $query,
            'limit' => $limit,
            'siteId' => $siteResolution['siteId'],
            'allSites' => $siteResolution['allSites'],
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
     * @return array{siteId: int|null, allSites: bool, validationError: array|null}
     */
    private static function resolveSiteId(mixed $rawSiteId): array
    {
        $sites = Craft::$app->getSites();
        $allSites = $sites->getAllSites();
        $siteCount = count($allSites);

        $omitted = $rawSiteId === null || $rawSiteId === '';

        if ($siteCount <= 1) {
            $onlySiteId = $siteCount === 1 ? (int)$allSites[0]->id : null;

            if ($omitted) {
                return ['siteId' => $onlySiteId, 'allSites' => false, 'validationError' => null];
            }

            $siteId = (int)$rawSiteId;
            if ($siteId === 0 || $siteId === $onlySiteId) {
                return ['siteId' => $onlySiteId, 'allSites' => false, 'validationError' => null];
            }

            return [
                'siteId' => null,
                'allSites' => false,
                'validationError' => self::validationErrorBody(),
            ];
        }

        if ($omitted) {
            return [
                'siteId' => null,
                'allSites' => false,
                'validationError' => self::validationErrorBody(),
            ];
        }

        $siteId = (int)$rawSiteId;

        if ($siteId === 0) {
            return ['siteId' => null, 'allSites' => true, 'validationError' => null];
        }

        foreach ($allSites as $site) {
            if ((int)$site->id === $siteId) {
                return ['siteId' => $siteId, 'allSites' => false, 'validationError' => null];
            }
        }

        return [
            'siteId' => null,
            'allSites' => false,
            'validationError' => self::validationErrorBody(),
        ];
    }

    /**
     * @return array{success: false, code: string, message: string}
     */
    private static function validationErrorBody(): array
    {
        $code = ErrorCode::SEARCH_VALIDATION_FAILED;
        return [
            'success' => false,
            'code' => $code->value,
            'message' => Craft::t('smart-search', $code->message()),
        ];
    }
}

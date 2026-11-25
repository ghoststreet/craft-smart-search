<?php

namespace ghoststreet\craftsmartsearch\controllers;

use Craft;
use craft\elements\Entry;
use ghoststreet\craftsmartsearch\helpers\RequestParameterExtractor;
use ghoststreet\craftsmartsearch\SmartSearch;
use yii\web\Response;

class PreviewController extends BaseCpController
{
    public function actionIndex(): Response
    {
        $plugin = SmartSearch::getInstance();
        $sites = Craft::$app->getSites()->getAllSites();
        $syncJobs = $plugin->indexInspectionService->syncJobs();

        $incompleteSites = [];
        foreach ($plugin->indexInspectionService->getCachedCoverageBySite() as $site) {
            if ($site['stale'] + $site['notIndexed'] > 0) {
                $incompleteSites[] = $site + ['activeJob' => $syncJobs[$site['siteId']] ?? null];
            }
        }

        return $this->renderTemplate('smart-search/preview/index', [
            'settings' => $plugin->getSettings(),
            'sites' => $sites,
            'incompleteSites' => $incompleteSites,
            'wikiUrl' => SmartSearch::WIKI_URL,
        ]);
    }

    /**
     * Craft's native keyword search, used as the Preview page's baseline column.
     * Admin-only on purpose: this is a comparison, not a plugin search mode, so it
     * stays off the public API and out of search history. Marks the Preview step done.
     */
    public function actionCraftSearch(): Response
    {
        $this->requireAcceptsJson();

        $params = RequestParameterExtractor::extractSearchParams();

        if ($params['validationError'] !== null) {
            return $this->badRequest($params['validationError']);
        }

        DashboardController::markStepDone(DashboardController::PREVIEW_TRIED_PREFERENCE);

        $query = Entry::find()
            ->status(Entry::STATUS_ENABLED)
            ->limit($params['limit']);

        if ($params['siteId'] !== null) {
            $query->siteId($params['siteId']);
        }

        // Rank with a narrow ids() query, then load the entries unsorted and restore
        // the order in PHP: sorting full rows (with the `content` JSON column) in
        // MySQL can exhaust its sort buffer.
        $ids = (clone $query)
            ->search($params['query'])
            ->orderBy(['score' => SORT_DESC])
            ->ids();

        $entries = $ids === [] ? [] : (clone $query)
            ->id($ids)
            ->limit(null)
            ->orderBy(null)
            ->indexBy('id')
            ->all();

        $results = [];
        foreach ($ids as $id) {
            if (isset($entries[$id])) {
                $entry = $entries[$id];
                $results[] = [
                    'id' => $entry->id,
                    'title' => $entry->title,
                    'url' => $entry->getUrl(),
                ];
            }
        }

        return $this->asJson([
            'success' => true,
            'results' => $results,
        ]);
    }
}

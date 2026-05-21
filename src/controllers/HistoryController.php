<?php

namespace ghoststreet\craftsmartsearch\controllers;

use Craft;
use craft\web\Controller;
use ghoststreet\craftsmartsearch\SmartSearch;
use yii\web\Response;

/**
 * CP controller for the Search History page.
 */
class HistoryController extends Controller
{
    public function actionIndex(): Response
    {
        $this->requireAdmin();

        $request = Craft::$app->getRequest();
        $page = max(1, (int)$request->getParam('page', 1));
        $type = $request->getParam('type') ?: null;
        $days = $request->getParam('days');
        $days = is_numeric($days) && (int)$days > 0 ? (int)$days : null;
        $errorsOnly = (bool)$request->getParam('errorsOnly');
        $siteIdParam = $request->getParam('siteId');
        $siteId = is_numeric($siteIdParam) && (int)$siteIdParam > 0 ? (int)$siteIdParam : null;

        $history = SmartSearch::getInstance()->historyService;

        $page = $history->paginate($page, 25, [
            'type' => $type,
            'days' => $days,
            'errorsOnly' => $errorsOnly,
            'siteId' => $siteId,
        ]);

        $stats = $history->getStats($days);
        $detailsCount = $history->detailsCount();

        $sites = $history->getAvailableSites();

        return $this->renderTemplate('smart-search/history/index', [
            'selectedSubnavItem' => 'history',
            'page' => $page,
            'stats' => $stats,
            'detailsCount' => $detailsCount,
            'sites' => $sites,
            'filters' => [
                'type' => $type,
                'days' => $days,
                'errorsOnly' => $errorsOnly,
                'siteId' => $siteId,
            ],
        ]);
    }

    public function actionKeywords(): Response
    {
        $this->requireAdmin();

        $request = Craft::$app->getRequest();
        $days = $request->getParam('days');
        $days = is_numeric($days) && (int)$days > 0 ? (int)$days : null;
        $siteIdParam = $request->getParam('siteId');
        $siteId = is_numeric($siteIdParam) && (int)$siteIdParam > 0 ? (int)$siteIdParam : null;
        $limit = 25;

        $history = SmartSearch::getInstance()->historyService;

        return $this->renderTemplate('smart-search/history/keywords', [
            'selectedSubnavItem' => 'keywords',
            'keywords' => [
                'top' => $history->getTopKeywords($days, $siteId, $limit),
                'zeroResults' => $history->getZeroResultQueries($days, $siteId, $limit),
                'trending' => $history->getTrendingKeywords($siteId, 7, $limit),
                'sites' => $history->getAvailableSites(),
            ],
            'filters' => [
                'days' => $days,
                'siteId' => $siteId,
            ],
        ]);
    }

    public function actionPrune(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $settings = SmartSearch::getInstance()->getSettings();
        $deleted = SmartSearch::getInstance()->historyService->pruneOlderThan($settings->historyRetentionDays);

        Craft::$app->getSession()->setNotice("Pruned {$deleted} history rows older than {$settings->historyRetentionDays} days.");

        return $this->redirectToPostedUrl();
    }
}

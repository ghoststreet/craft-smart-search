<?php

namespace ghoststreet\craftsmartsearch\controllers;

use Craft;
use craft\web\Controller;
use ghoststreet\craftsmartsearch\services\HistoryService;
use ghoststreet\craftsmartsearch\SmartSearch;
use yii\web\Response;

/**
 * Insights pages: overview, top queries, zero-results, trending. One action per
 * page; shared filter parsing and nav data live in commonViewData().
 */
class InsightsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requireAdmin();
        return true;
    }

    public function actionIndex(): Response
    {
        if (($redirect = $this->redirectIfEmpty()) !== null) {
            return $redirect;
        }

        $common = $this->commonViewData();
        $request = Craft::$app->getRequest();
        $type = $request->getParam('type') ?: null;
        $errorsOnly = (bool)$request->getParam('errorsOnly');
        $page = max(1, (int)$request->getParam('page', 1));
        $days = $common['filters']['days'];
        $siteId = $common['filters']['siteId'];

        $common['filters']['type'] = $type;
        $common['filters']['errorsOnly'] = $errorsOnly;

        $history = $this->history();

        return $this->renderTemplate('smart-search/insights/index', array_merge($common, [
            'stats' => $history->getStats($days),
            'page' => $history->paginate($page, 25, [
                'type' => $type,
                'days' => $days,
                'errorsOnly' => $errorsOnly,
                'siteId' => $siteId,
            ]),
        ]));
    }

    public function actionTopQueries(): Response
    {
        if (($redirect = $this->redirectIfEmpty()) !== null) {
            return $redirect;
        }

        $common = $this->commonViewData();
        $history = $this->history();
        $days = $common['filters']['days'];
        $siteId = $common['filters']['siteId'];

        return $this->renderTemplate('smart-search/insights/top-queries', array_merge($common, [
            'topQueries' => $history->getTopKeywords($days, $siteId, 30),
            'totalSearches' => $history->countSearches($days, $siteId),
        ]));
    }

    public function actionZeroResults(): Response
    {
        if (($redirect = $this->redirectIfEmpty()) !== null) {
            return $redirect;
        }

        $common = $this->commonViewData();
        $page = max(1, (int)Craft::$app->getRequest()->getParam('page', 1));
        $days = $common['filters']['days'];
        $siteId = $common['filters']['siteId'];

        return $this->renderTemplate('smart-search/insights/zero-results', array_merge($common, [
            'page' => $this->history()->paginateKeywords($days, $siteId, true, $page, 25),
        ]));
    }

    public function actionTrending(): Response
    {
        if (($redirect = $this->redirectIfEmpty()) !== null) {
            return $redirect;
        }

        $common = $this->commonViewData();
        $page = max(1, (int)Craft::$app->getRequest()->getParam('page', 1));

        return $this->renderTemplate('smart-search/insights/trending', array_merge($common, [
            'page' => $this->history()->paginateTrending($common['filters']['siteId'], 7, $page, 25),
        ]));
    }

    private function commonViewData(): array
    {
        $request = Craft::$app->getRequest();
        $daysRaw = $request->getParam('days');
        $siteIdRaw = $request->getParam('siteId');

        return [
            'selectedSubnavItem' => 'insights',
            'sites' => $this->history()->getAvailableSites(),
            'filters' => [
                'days' => is_numeric($daysRaw) && (int)$daysRaw > 0 ? (int)$daysRaw : null,
                'siteId' => is_numeric($siteIdRaw) && (int)$siteIdRaw > 0 ? (int)$siteIdRaw : null,
                'type' => null,
                'errorsOnly' => false,
            ],
        ];
    }

    private function redirectIfEmpty(): ?Response
    {
        if ($this->history()->count() === 0) {
            return $this->redirect('smart-search');
        }
        return null;
    }

    private function history(): HistoryService
    {
        return SmartSearch::getInstance()->historyService;
    }
}

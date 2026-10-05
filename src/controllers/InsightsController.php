<?php

namespace ghoststreet\craftsmartsearch\controllers;

use Craft;
use ghoststreet\craftsmartsearch\helpers\RequestParameterExtractor;
use ghoststreet\craftsmartsearch\services\HistoryService;
use ghoststreet\craftsmartsearch\SmartSearch;
use yii\web\Response;

/**
 * Insights. One page, one action; the `report` param picks which table renders.
 * Shared filter parsing and nav data live in commonViewData().
 */
class InsightsController extends BaseCpController
{
    private const REPORTS = [
        'overview' => 'Overview',
        'top-queries' => 'Top Queries',
        'zero-results' => 'Zero Results',
        'trending' => 'Trending',
    ];

    private const PER_PAGE = 25;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        if (!$this->history()->hasAny()) {
            $this->redirect('smart-search')->send();
            return false;
        }

        return true;
    }

    public function actionIndex(?string $report = null): Response
    {
        $request = Craft::$app->getRequest();
        $common = $this->commonViewData();
        $history = $this->history();

        if (!isset(self::REPORTS[$report])) {
            $report = 'overview';
        }

        $page = max(1, (int)$request->getParam('page', 1));
        $days = $common['filters']['days'];
        $siteId = $common['filters']['siteId'];

        $data = [];

        switch ($report) {
            case 'top-queries':
                $data = [
                    'page' => $history->paginateKeywords($days, $siteId, false, $page, self::PER_PAGE),
                    'totalSearches' => $history->countSearches($days, $siteId),
                ];
                break;

            case 'zero-results':
                $data = [
                    'page' => $history->paginateKeywords($days, $siteId, true, $page, self::PER_PAGE),
                ];
                break;

            case 'trending':
                $data = [
                    'page' => $history->paginateTrending($siteId, $page, self::PER_PAGE),
                ];
                break;

            default:
                $common['filters']['type'] = $request->getParam('type') ?: null;
                $common['filters']['errorsOnly'] = (bool)$request->getParam('errorsOnly');
                $data = [
                    'page' => $history->paginate($page, self::PER_PAGE, [
                        'type' => $common['filters']['type'],
                        'days' => $days,
                        'errorsOnly' => $common['filters']['errorsOnly'],
                        'siteId' => $siteId,
                    ]),
                ];
        }

        return $this->renderTemplate('smart-search/insights/index', array_merge($common, $data, [
            'report' => $report,
            'reports' => self::REPORTS,
            'trendingDays' => HistoryService::TRENDING_DAYS,
        ]));
    }

    private function commonViewData(): array
    {
        $request = Craft::$app->getRequest();
        $daysRaw = $request->getParam('days');
        $siteIdRaw = $request->getParam('siteId');

        return [
            'sites' => Craft::$app->getSites()->getAllSites(),
            'filters' => [
                'days' => is_numeric($daysRaw) && (int)$daysRaw > 0 ? (int)$daysRaw : null,
                'siteId' => $siteIdRaw ? RequestParameterExtractor::resolveSiteId($siteIdRaw)[0] : null,
                'type' => null,
                'errorsOnly' => false,
            ],
        ];
    }

    private function history(): HistoryService
    {
        return SmartSearch::getInstance()->historyService;
    }
}

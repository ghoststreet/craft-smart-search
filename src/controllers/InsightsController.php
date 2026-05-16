<?php

namespace ghoststreet\craftaisearch\controllers;

use Craft;
use craft\web\Controller;
use ghoststreet\craftaisearch\AiSearch;
use ghoststreet\craftaisearch\helpers\PricingTable;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Insights page: unified queries / zero-results / trending / history-log view.
 * Replaces the previous History + Keywords pages.
 */
class InsightsController extends Controller
{
    public function actionIndex(): Response
    {
        $this->requireAdmin();

        if (AiSearch::getInstance()->historyService->detailsCount() === 0) {
            return $this->redirect('ai-search');
        }

        $request = Craft::$app->getRequest();
        $tab = $request->getParam('tab') ?: 'queries';
        $days = $request->getParam('days');
        $days = is_numeric($days) && (int)$days > 0 ? (int)$days : null;
        $siteIdParam = $request->getParam('siteId');
        $siteId = is_numeric($siteIdParam) && (int)$siteIdParam > 0 ? (int)$siteIdParam : null;
        $type = $request->getParam('type') ?: null;
        $errorsOnly = (bool)$request->getParam('errorsOnly');
        $page = max(1, (int)$request->getParam('page', 1));

        $history = AiSearch::getInstance()->historyService;

        $data = [
            'tab' => $tab,
            'filters' => [
                'days' => $days,
                'siteId' => $siteId,
                'type' => $type,
                'errorsOnly' => $errorsOnly,
            ],
            'sites' => $history->getAvailableSites(),
            'stats' => $history->getStats($days),
            'detailsCount' => $history->detailsCount(),
            'selectedSubnavItem' => 'insights',
        ];

        switch ($tab) {
            case 'zero-results':
                $data['page'] = $history->paginateKeywords($days, $siteId, true, $page, 25);
                break;
            case 'trending':
                $data['page'] = $history->paginateTrending($siteId, 7, $page, 25);
                break;
            case 'history':
                $data['page'] = $history->paginate($page, 25, [
                    'type' => $type,
                    'days' => $days,
                    'errorsOnly' => $errorsOnly,
                    'siteId' => $siteId,
                ]);
                break;
            case 'queries':
            default:
                $data['page'] = $history->paginateKeywords($days, $siteId, false, $page, 25);
                $data['tab'] = 'queries';
                break;
        }

        return $this->renderTemplate('ai-search/insights/index', $data);
    }

    public function actionDetail(int $id): Response
    {
        $this->requireAdmin();

        $row = AiSearch::getInstance()->historyService->findOne($id);
        if ($row === null) {
            throw new NotFoundHttpException('Search history entry not found.');
        }

        return $this->renderTemplate('ai-search/insights/detail', [
            'selectedSubnavItem' => 'insights',
            'row' => $row,
            'embeddingRates' => PricingTable::getRates($row['embeddingModel'] ?? null),
            'ragRates' => PricingTable::getRates($row['ragModel'] ?? null),
        ]);
    }

    public function actionPrune(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $settings = AiSearch::getInstance()->getSettings();
        $deleted = AiSearch::getInstance()->historyService->pruneOlderThan($settings->historyRetentionDays);

        Craft::$app->getSession()->setNotice("Pruned {$deleted} history rows older than {$settings->historyRetentionDays} days.");
        return $this->redirectToPostedUrl();
    }

    public function actionClear(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $deleted = AiSearch::getInstance()->historyService->clearAllDetails();
        Craft::$app->getSession()->setNotice("Cleared {$deleted} history rows. Token and cost stats are preserved.");

        return $this->redirectToPostedUrl();
    }

    public function actionLegacyRedirect(): Response
    {
        return $this->redirect('ai-search/insights');
    }
}

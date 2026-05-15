<?php

namespace ghoststreet\craftaisearch\controllers;

use Craft;
use craft\web\Controller;
use ghoststreet\craftaisearch\AiSearch;
use ghoststreet\craftaisearch\helpers\PricingTable;
use yii\web\NotFoundHttpException;
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

        $history = AiSearch::getInstance()->historyService;

        $page = $history->paginate($page, 25, [
            'type' => $type,
            'days' => $days,
            'errorsOnly' => $errorsOnly,
        ]);

        $stats = $history->getStats($days);
        $detailsCount = $history->detailsCount();

        return $this->renderTemplate('ai-search/history/index', [
            'selectedSubnavItem' => 'history',
            'page' => $page,
            'stats' => $stats,
            'detailsCount' => $detailsCount,
            'filters' => [
                'type' => $type,
                'days' => $days,
                'errorsOnly' => $errorsOnly,
            ],
        ]);
    }

    public function actionDetail(int $id): Response
    {
        $this->requireAdmin();

        $row = AiSearch::getInstance()->historyService->findOne($id);
        if ($row === null) {
            throw new NotFoundHttpException('Search history entry not found.');
        }

        return $this->renderTemplate('ai-search/history/detail', [
            'selectedSubnavItem' => 'history',
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
}

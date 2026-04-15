<?php

namespace ghoststreet\craftaisearch\controllers;

use craft\web\Controller;
use ghoststreet\craftaisearch\AiSearch;
use ghoststreet\craftaisearch\exceptions\DatabaseException;
use yii\web\Response;

/**
 * Dashboard controller displaying plugin statistics and connection status.
 */
class DashboardController extends Controller
{
    /**
     * Render the AI Search dashboard with stats and settings overview.
     */
    public function actionIndex(): Response
    {
        $plugin = AiSearch::getInstance();

        try {
            $stats = $plugin->databaseService->getStats();
        } catch (DatabaseException $e) {
            $stats = [
                'entryCount' => 0,
                'chunkCount' => 0,
                'lastIndexed' => null,
                'isConnected' => false,
                'error' => $e->getMessage(),
            ];
        }

        return $this->renderTemplate('ai-search/index', [
            'plugin' => $plugin,
            'settings' => $plugin->getSettings(),
            'stats' => $stats,
            'selectedSubnavItem' => 'dashboard',
        ]);
    }
}

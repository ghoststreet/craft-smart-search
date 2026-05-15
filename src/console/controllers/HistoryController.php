<?php

namespace ghoststreet\craftaisearch\console\controllers;

use ghoststreet\craftaisearch\AiSearch;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Console commands for AI Search history.
 *
 * Usage:
 *   php craft ai-search/history/prune
 *   php craft ai-search/history/clear
 */
class HistoryController extends Controller
{
    public function actionPrune(?int $days = null): int
    {
        $days = $days ?? AiSearch::getInstance()->getSettings()->historyRetentionDays;
        $deleted = AiSearch::getInstance()->historyService->pruneOlderThan($days);
        $this->stdout("Pruned {$deleted} history detail rows older than {$days} days.\n");
        return ExitCode::OK;
    }

    public function actionClear(): int
    {
        $deleted = AiSearch::getInstance()->historyService->clearAllDetails();
        $this->stdout("Cleared {$deleted} history detail rows. Stats preserved.\n");
        return ExitCode::OK;
    }
}

<?php

namespace ghoststreet\craftsmartsearch\console\controllers;

use ghoststreet\craftsmartsearch\SmartSearch;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Console commands for Smart Search history.
 *
 * Usage:
 *   php craft smart-search/history/prune
 */
class HistoryController extends Controller
{
    public function actionPrune(?int $days = null): int
    {
        $days = $days ?? SmartSearch::getInstance()->getSettings()->historyRetentionDays;
        $deleted = SmartSearch::getInstance()->historyService->pruneOlderThan($days);
        $this->stdout("Pruned {$deleted} history detail rows older than {$days} days.\n");
        return ExitCode::OK;
    }
}

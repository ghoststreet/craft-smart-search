<?php

namespace ghoststreet\craftsmartsearch\console\controllers;

use Craft;
use craft\console\Controller;
use ghoststreet\craftsmartsearch\exceptions\DatabaseException;
use ghoststreet\craftsmartsearch\helpers\Logger;
use ghoststreet\craftsmartsearch\jobs\SyncSearchIndexJob;
use ghoststreet\craftsmartsearch\SmartSearch;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Console controller for bulk indexing entries into the Smart Search vector database.
 *
 * Supports filtering by site and section. By default performs an incremental
 * reindex (upserts). Use --wipe to clear all vectors before re-indexing.
 */
class IndexController extends Controller
{
    public $defaultAction = 'index';
    public ?int $siteId = null;
    public ?string $section = null;
    public bool $wipe = false;

    /**
     * Register CLI options for the index action.
     */
    public function options($actionID): array
    {
        return array_merge(
            parent::options($actionID),
            $actionID === 'index' ? ['siteId', 'section', 'wipe'] : []
        );
    }

    /**
     * Bulk-index all entries (or a filtered subset).
     * Incremental by default (upserts existing vectors). Use --wipe to clear first.
     */
    public function actionIndex(): int
    {
        $this->stdout("Starting bulk indexing...\n", Console::FG_GREEN);

        try {
            SmartSearch::getInstance()->databaseService->preflightSchema();

            if ($this->wipe) {
                $this->stdout("Wiping existing vectors...\n");
                $count = SmartSearch::getInstance()->databaseService->clearAllVectors();
                $this->stdout("Deleted {$count} existing vectors.\n");
            } else {
                $this->stdout("Incremental mode: existing vectors will be updated.\n");
            }
        } catch (DatabaseException $e) {
            Logger::exception($e, 'console.index');
            $this->stdout("Database error: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        Craft::$app->getQueue()->push(new SyncSearchIndexJob([
            'siteId' => $this->siteId,
            'section' => $this->section,
        ]));

        $this->stdout("Queued the index sync job.\n", Console::FG_GREEN);
        $this->stdout("Run `./craft queue/run` (or your queue runner) to process it.\n");

        return ExitCode::OK;
    }
}

<?php

namespace ghoststreet\craftaisearch\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\Entry;
use ghoststreet\craftaisearch\AiSearch;
use ghoststreet\craftaisearch\exceptions\DatabaseException;
use ghoststreet\craftaisearch\jobs\IndexEntryJob;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Console controller for bulk indexing entries into the AI search vector database.
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
        $options = parent::options($actionID);

        switch ($actionID) {
            case 'index':
                $options[] = 'siteId';
                $options[] = 'section';
                $options[] = 'wipe';
                break;
        }

        return $options;
    }

    /**
     * Bulk-index all entries (or a filtered subset).
     * Incremental by default (upserts existing vectors). Use --wipe to clear first.
     */
    public function actionIndex(): int
    {
        $this->stdout("Starting bulk indexing...\n", Console::FG_GREEN);

        try {
            if (!AiSearch::getInstance()->databaseService->isSchemaInitialized()) {
                $this->stdout("Initializing database schema...\n");
                AiSearch::getInstance()->databaseService->initializeSchema();
            }

            if ($this->wipe) {
                $this->stdout("Wiping existing vectors...\n");
                $count = AiSearch::getInstance()->databaseService->clearAllVectors();
                $this->stdout("Deleted {$count} existing vectors.\n");
            } else {
                $this->stdout("Incremental mode: existing vectors will be updated.\n");
            }
        } catch (DatabaseException $e) {
            $this->stdout("Database error: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $query = Entry::find();

        if ($this->siteId !== null) {
            $query->siteId($this->siteId);
        }

        if ($this->section !== null) {
            $query->section($this->section);
        }

        $total = $query->count();

        $this->stdout("Found {$total} entries to index.\n");

        if ($total === 0) {
            $this->stdout("No entries to index.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $queued = 0;
        $perSection = [];

        foreach ($query->each() as $entry) {
            Craft::$app->getQueue()->push(new IndexEntryJob([
                'entryId' => $entry->id,
                'siteId' => $entry->siteId,
            ]));
            $queued++;

            $sectionHandle = $entry->getSection()?->handle ?? 'unknown';
            $perSection[$sectionHandle] = ($perSection[$sectionHandle] ?? 0) + 1;
        }

        $this->stdout("Queued {$queued} IndexEntryJob(s).\n", Console::FG_GREEN);

        foreach ($perSection as $handle => $count) {
            $this->stdout("  {$handle}: {$count}\n");
        }

        $this->stdout("Run `./craft queue/run` (or your queue runner) to process the jobs.\n");

        return ExitCode::OK;
    }
}

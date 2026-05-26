<?php

namespace ghoststreet\craftsmartsearch\console\controllers;

use Craft;
use craft\console\Controller;
use ghoststreet\craftsmartsearch\jobs\RebuildDictionaryJob;
use ghoststreet\craftsmartsearch\SmartSearch;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Console actions for the typo-tolerance dictionary.
 *
 * - `smart-search/dictionary/rebuild` runs the rebuild inline (blocks until done).
 * - `smart-search/dictionary/queue` pushes a RebuildDictionaryJob onto Craft's queue.
 * - `smart-search/dictionary/status` reports whether typo correction is available.
 */
class DictionaryController extends Controller
{
    public $defaultAction = 'status';

    public function actionRebuild(): int
    {
        $this->stdout("Ensuring dictionary schema...\n");
        $tableReady = SmartSearch::getInstance()->dictionaryService->ensureSchema();

        if (!$tableReady) {
            $this->stdout(
                "Dictionary table could not be created. Typo tolerance will stay disabled.\n" .
                "Run the following as a Postgres superuser, then re-run this command:\n" .
                "  CREATE EXTENSION IF NOT EXISTS pg_trgm;\n" .
                "  CREATE EXTENSION IF NOT EXISTS fuzzystrmatch;\n",
                Console::FG_YELLOW
            );
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Rebuilding dictionary from indexed content...\n");
        $rows = SmartSearch::getInstance()->dictionaryService->rebuild();

        if ($rows === null) {
            $this->stdout("Rebuild failed — see storage/logs/smart-search.log.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Done. {$rows} terms written.\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    public function actionQueue(): int
    {
        Craft::$app->getQueue()->push(new RebuildDictionaryJob());
        $this->stdout("Queued RebuildDictionaryJob. Run `./craft queue/run` to process it.\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    public function actionStatus(): int
    {
        $service = SmartSearch::getInstance()->dictionaryService;
        $available = $service->isAvailable();
        $hasFuzzy = $service->hasFuzzyStrMatch();
        $enabled = SmartSearch::getInstance()->getSettings()->enableTypoTolerance;

        $this->stdout("Setting enableTypoTolerance: " . ($enabled ? "on\n" : "off\n"));
        $this->stdout("pg_trgm + dictionary available: " . ($available ? "yes\n" : "no\n"));
        $this->stdout("fuzzystrmatch (Levenshtein cap): " . ($hasFuzzy ? "yes\n" : "no\n"));
        return ExitCode::OK;
    }
}

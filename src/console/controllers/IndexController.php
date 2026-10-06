<?php

namespace ghoststreet\craftsmartsearch\console\controllers;

use Craft;
use craft\console\Controller;
use ghoststreet\craftsmartsearch\engines\local\LocalContextVectors;
use ghoststreet\craftsmartsearch\engines\local\LocalIndexService;
use ghoststreet\craftsmartsearch\engines\local\LocalSearchService;
use ghoststreet\craftsmartsearch\SmartSearch;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Console actions for the search index.
 *
 * - `smart-search/index` queues a batched rebuild of the index.
 * - `smart-search/index/rebuild-postings` re-tokenises the keyword index from the stored
 *   chunk text, for a tokeniser change, without re-embedding anything.
 * - `smart-search/index/rebuild-dictionary` repairs the term dictionary from the postings.
 * - `smart-search/index/rebuild-vectors` relearns related terms from your content (no API key only).
 * - `smart-search/index/stats` reports the numbers behind the dashboard meter.
 * - `smart-search/index/engines` reports which engines this host can run.
 */
class IndexController extends Controller
{
    public $defaultAction = 'index';

    /** Restrict to one site. */
    public ?int $siteId = null;

    /** Restrict to one section handle. */
    public ?string $section = null;

    /** Empty the local index before rebuilding, rather than updating in place. */
    public bool $wipe = false;

    public function options($actionID): array
    {
        return match ($actionID) {
            'index' => ['siteId', 'section', 'wipe'],
            'rebuild-dictionary', 'rebuild-postings', 'rebuild-vectors', 'stats' => ['siteId'],
            default => [],
        };
    }

    public function actionIndex(): int
    {
        $section = $this->section !== null ? Craft::$app->getEntries()->getSectionByHandle($this->section) : null;

        if ($this->section !== null && $section === null) {
            $this->stderr("Unknown section \"{$this->section}\".\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        if ($section !== null && !in_array((int)$section->id, SmartSearch::getInstance()->getSettings()->indexedSectionIds(), true)) {
            $this->stderr("Section \"{$this->section}\" is turned off in the Indexing settings.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        if ($this->wipe && $this->section !== null) {
            $this->stderr("--wipe cannot be combined with --section.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        if ($this->wipe) {
            $this->stdout("Emptying the local index...\n");
            $deleted = SmartSearch::getInstance()->engine()->clearAll($this->siteId);
            $this->stdout("Deleted {$deleted} chunks.\n");
        } else {
            $this->stdout("Incremental mode: unchanged entries will be skipped.\n");
        }

        SmartSearch::getInstance()->engine()->queueSync($this->siteId, $this->section);

        $scope = ($this->siteId !== null ? "site #{$this->siteId}" : 'every site') . ($this->section !== null ? ", section \"{$this->section}\"" : '');
        $this->stdout("Queued a sync of {$scope}.\n", Console::FG_GREEN);
        $this->stdout("Run `./craft queue/run` (or your queue runner) to process it.\n");

        return ExitCode::OK;
    }

    public function actionRebuildPostings(): int
    {
        $this->stdout("Re-tokenising the postings from the stored chunk text...\n");
        $entries = LocalIndexService::instance()->rebuildPostings($this->siteId);
        $this->stdout("Done. {$entries} entries rebuilt, no embeddings needed.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    public function actionRebuildDictionary(): int
    {
        $this->stdout("Rebuilding the local dictionary from the postings...\n");
        $terms = LocalIndexService::instance()->rebuildDictionary($this->siteId);
        $this->stdout("Done. {$terms} terms written.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    public function actionRebuildVectors(): int
    {
        $source = SmartSearch::getInstance()->vectorSource();
        if (!SmartSearch::getInstance()->vectorSources->isLocal()) {
            $this->stdout("Vectors come from {$source->label()}. Related terms are only learned when no API key is set.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $this->stdout("Learning related terms from your content...\n");
        $terms = LocalContextVectors::instance()->rebuild($this->siteId);

        if ($terms === 0) {
            $this->stdout("No model built: the corpus is too small for related terms to mean anything. Keyword scoring is unaffected.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $this->stdout("Done. {$terms} terms modelled, no API calls.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    public function actionStats(): int
    {
        $stats = SmartSearch::getInstance()->engine()->stats($this->siteId);
        $source = SmartSearch::getInstance()->vectorSource();

        $this->stdout("Vectors: {$source->label()} ({$source->handle()}) at {$stats['dimensions']} dimensions ({$stats['bytesPerChunk']} bytes per chunk)\n\n");

        if ($stats['sites'] === []) {
            $this->stdout("Nothing indexed yet. Run smart-search/index.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        foreach ($stats['sites'] as $site) {
            $label = $site['name'] === "site {$site['siteId']}"
                ? $site['name']
                : "{$site['name']} (site {$site['siteId']})";
            $this->stdout("{$label}\n", Console::BOLD);
            $this->stdout(sprintf(
                "  %d entries, %d chunks, %d vectors, %d postings, %d terms\n",
                $site['entries'], $site['chunks'], $site['vectors'], $site['postings'], $site['terms'],
            ));
            $this->stdout('  vector data: ' . self::formatBytes($site['vectorBytes'])
                . ", mean chunk length {$site['avgTokens']} tokens\n");
            if ($site['staleVectors'] > 0) {
                $this->stdout(
                    "  {$site['staleVectors']} vectors are stored at another width and are being skipped. Reindex.\n",
                    Console::FG_YELLOW
                );
            }
            $this->stdout("  last indexed: {$site['lastIndexed']}\n\n");
        }

        $totals = $stats['totals'];
        $this->stdout('Total vector data: ' . self::formatBytes($totals['vectorBytes']) . "\n");

        if ($stats['dbCacheBytes'] !== null) {
            $share = $stats['dbCacheBytes'] > 0 ? ($totals['vectorBytes'] / $stats['dbCacheBytes']) * 100 : 0.0;
            $this->stdout(sprintf(
                "Database page cache: %s (vectors are %.1f%% of it)\n",
                self::formatBytes($stats['dbCacheBytes']),
                $share,
            ));
        }

        if ($stats['scanP95Ms'] !== null) {
            $colour = match (true) {
                $stats['scanP95Ms'] >= LocalSearchService::SCAN_CRIT_MS => Console::FG_RED,
                $stats['scanP95Ms'] >= LocalSearchService::SCAN_WARN_MS => Console::FG_YELLOW,
                default => Console::FG_GREEN,
            };
            $this->stdout(sprintf(
                "Scan p95: %.1f ms over the last %d searches (warn %d, critical %d)\n",
                $stats['scanP95Ms'], $stats['scanSampleCount'],
                LocalSearchService::SCAN_WARN_MS, LocalSearchService::SCAN_CRIT_MS,
            ), $colour);
        } else {
            $this->stdout("Scan p95: no searches recorded yet.\n");
        }

        return ExitCode::OK;
    }

    /**
     * What each engine this host can run reports about its own store.
     *
     * The engines are isolated by design, so this cannot be a correctness diff: they
     * rank differently on purpose and neither is ground truth for the other. What it
     * does check is that every supported engine answers the SearchEngine contract
     * without throwing, and reports what each holds, which is what you want before
     * moving a site from one to the other.
     */
    public function actionEngines(): int
    {
        $registry = SmartSearch::getInstance()->engines;
        $active = $registry->active();

        foreach ($registry->supported() as $engine) {
            $mark = $engine::class === $active::class ? '*' : ' ';
            $this->stdout(sprintf("%s %s (%s)\n", $mark, $engine->label(), $engine->handle()), Console::BOLD);

            if (!$engine->isInstalled()) {
                $this->stdout("    not installed on this database\n", Console::FG_YELLOW);
                continue;
            }

            $stats = $engine->stats();
            $this->stdout(sprintf(
                "    %d entries, %d chunks, last indexed %s\n",
                $stats['entryCount'],
                $stats['chunkCount'],
                $stats['lastIndexed'] ?? 'never',
            ));
        }

        $this->stdout("\n* serves every search on this host.\n");

        return ExitCode::OK;
    }

    private static function formatBytes(int $bytes): string
    {
        return Craft::$app->getFormatter()->asShortSize($bytes, 1);
    }
}

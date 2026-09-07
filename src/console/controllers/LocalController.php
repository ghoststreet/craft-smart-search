<?php

namespace ghoststreet\craftsmartsearch\console\controllers;

use Craft;
use craft\console\Controller;
use ghoststreet\craftsmartsearch\helpers\TimingProfiler;
use ghoststreet\craftsmartsearch\jobs\LocalSyncIndexJob;
use ghoststreet\craftsmartsearch\SmartSearch;
use Throwable;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Console actions for the `local` search type.
 *
 * - `smart-search/local/reindex` queues a batched rebuild of the local index.
 * - `smart-search/local/rebuild-postings` re-tokenises the keyword index from the stored
 *   chunk text, for a tokeniser change, without re-embedding anything.
 * - `smart-search/local/rebuild-dictionary` repairs the term dictionary from the postings.
 * - `smart-search/local/stats` reports the numbers behind the dashboard meter.
 * - `smart-search/local/compare` runs a query set through both types and reports how
 *   they differ, which is the deliverable this search type exists to produce.
 */
class LocalController extends Controller
{
    public $defaultAction = 'stats';

    /** Restrict to one site. */
    public ?int $siteId = null;

    /** Restrict to one section handle. */
    public ?string $section = null;

    /** Empty the local index before rebuilding, rather than updating in place. */
    public bool $wipe = false;

    /** File of queries to compare, one per line; blank lines and `#` comments ignored. */
    public ?string $queries = null;

    /** Results per query. */
    public int $limit = 10;

    public function options($actionID): array
    {
        return match ($actionID) {
            'reindex' => ['siteId', 'section', 'wipe'],
            'rebuild-dictionary', 'rebuild-postings', 'stats' => ['siteId'],
            'compare' => ['queries', 'limit', 'siteId'],
            default => [],
        };
    }

    public function actionReindex(): int
    {
        if ($this->wipe) {
            $this->stdout("Emptying the local index...\n");
            $deleted = SmartSearch::getInstance()->localIndexService->clearAll($this->siteId);
            $this->stdout("Deleted {$deleted} chunks.\n");
        } else {
            $this->stdout("Incremental mode: unchanged entries will be skipped.\n");
        }

        Craft::$app->getQueue()->push(new LocalSyncIndexJob([
            'siteId' => $this->siteId,
            'section' => $this->section,
        ]));

        $this->stdout("Queued the local index sync job.\n", Console::FG_GREEN);
        $this->stdout("Run `./craft queue/run` (or your queue runner) to process it.\n");

        return ExitCode::OK;
    }

    public function actionRebuildPostings(): int
    {
        $this->stdout("Re-tokenising the postings from the stored chunk text...\n");
        $entries = SmartSearch::getInstance()->localIndexService->rebuildPostings($this->siteId);
        $this->stdout("Done. {$entries} entries rebuilt, no embeddings needed.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    public function actionRebuildDictionary(): int
    {
        $this->stdout("Rebuilding the local dictionary from the postings...\n");
        $terms = SmartSearch::getInstance()->localIndexService->rebuildDictionary($this->siteId);
        $this->stdout("Done. {$terms} terms written.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    public function actionStats(): int
    {
        $stats = SmartSearch::getInstance()->localIndexService->stats($this->siteId);
        $settings = SmartSearch::getInstance()->getSettings();

        $this->stdout("Model: {$settings->localEmbeddingModel} at {$stats['dimensions']} dimensions ({$stats['bytesPerChunk']} bytes per chunk)\n\n");

        if ($stats['sites'] === []) {
            $this->stdout("Nothing indexed yet. Run smart-search/local/reindex.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        foreach ($stats['sites'] as $site) {
            /* The name already falls back to "site N", so only add the id when the site
               actually has a name of its own. */
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
                    "  {$site['staleVectors']} vectors are stored at another width and are being skipped — reindex.\n",
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
                $stats['scanP95Ms'] >= $settings->localScanCritMs => Console::FG_RED,
                $stats['scanP95Ms'] >= $settings->localScanWarnMs => Console::FG_YELLOW,
                default => Console::FG_GREEN,
            };
            $this->stdout(sprintf(
                "Scan p95: %.1f ms over the last %d searches (warn %d, critical %d)\n",
                $stats['scanP95Ms'], $stats['scanSampleCount'],
                $settings->localScanWarnMs, $settings->localScanCritMs,
            ), $colour);
        } else {
            $this->stdout("Scan p95: no searches recorded yet.\n");
        }

        return ExitCode::OK;
    }

    /**
     * Run a query set through both search types and report how far apart they land.
     *
     * In-process rather than over HTTP: the API needs a CSRF token and is rate limited,
     * neither of which has anything to do with ranking, and peak memory per type is only
     * measurable from inside.
     *
     * The two arms alternate which runs first, because the OpenAI call varies by a few
     * hundred milliseconds and any drift must hit both arms equally. The embedding cache
     * is deliberately left alone: OpenAI's embeddings are not bit-identical across
     * calls, so clearing it produces score drift that reads as a code difference.
     */
    public function actionCompare(): int
    {
        if ($this->queries === null) {
            $this->stderr("Pass --queries=<file>, one query per line.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $path = Craft::getAlias($this->queries);
        if (!is_file($path)) {
            $this->stderr("No such file: {$path}\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $queries = array_values(array_filter(array_map(
            static fn(string $line): string => trim($line),
            file($path) ?: [],
        ), static fn(string $line): bool => $line !== '' && !str_starts_with($line, '#')));

        if ($queries === []) {
            $this->stderr("The query file has no queries in it.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        /*
         * Both types cache their ranking for 60 seconds, so a second run of the same
         * query set measures the caches rather than the ranking. Invalidate them, but
         * leave the embedding cache alone: OpenAI's embeddings are not bit-identical
         * across calls, and refetching them shows up as score drift that reads as a code
         * difference.
         */
        SmartSearch::getInstance()->databaseService->bumpVectorsCacheToken();
        SmartSearch::getInstance()->localIndexService->bumpCacheToken();

        $arms = [
            'pgvector' => fn(string $q): array => SmartSearch::getInstance()->smartSearchService->search($q, $this->limit, $this->siteId),
            'local' => fn(string $q): array => SmartSearch::getInstance()->localSearchService->search($q, $this->limit, $this->siteId),
        ];

        $phaseSamples = ['pgvector' => [], 'local' => []];
        $memoryPeaks = ['pgvector' => [], 'local' => []];
        $overlaps = [];
        $correlations = [];

        foreach ($queries as $index => $query) {
            $this->stdout("\n" . str_pad('', 72, '-') . "\n");
            $this->stdout("{$query}\n", Console::BOLD);

            /* Alternate the order so a slow minute cannot land entirely on one arm. */
            $order = $index % 2 === 0 ? ['pgvector', 'local'] : ['local', 'pgvector'];

            $ids = [];
            foreach ($order as $arm) {
                TimingProfiler::reset();
                if (function_exists('memory_reset_peak_usage')) {
                    memory_reset_peak_usage();
                }

                try {
                    $results = $arms[$arm]($query);
                } catch (Throwable $e) {
                    $this->stdout("  {$arm}: " . get_class($e) . ' — ' . $e->getMessage() . "\n", Console::FG_RED);
                    $ids[$arm] = [];
                    continue;
                }

                $memoryPeaks[$arm][] = memory_get_peak_usage(true);
                $ids[$arm] = array_map(static fn(array $r): int => (int)$r['element']->id, $results);

                foreach (TimingProfiler::phases() as $phase) {
                    $phaseSamples[$arm][$phase['name']][] = $phase['ms'];
                }

                $this->stdout(sprintf("  %-9s %2d results  %s\n", $arm, count($results), implode(' ', $ids[$arm])));
            }

            $jaccard = self::jaccard($ids['pgvector'] ?? [], $ids['local'] ?? []);
            $rho = self::spearman($ids['pgvector'] ?? [], $ids['local'] ?? []);
            $overlaps[] = $jaccard;
            if ($rho !== null) {
                $correlations[] = $rho;
            }

            $this->stdout(sprintf(
                "  overlap %.2f   rank correlation %s   shared %d\n",
                $jaccard,
                $rho === null ? 'n/a' : sprintf('%+.2f', $rho),
                count(array_intersect($ids['pgvector'] ?? [], $ids['local'] ?? [])),
            ));
        }

        $this->stdout("\n" . str_pad('', 72, '=') . "\n");
        $this->stdout("Summary over " . count($queries) . " queries\n\n", Console::BOLD);

        $this->stdout(sprintf("Top-%d overlap (Jaccard): mean %.2f, min %.2f\n",
            $this->limit, array_sum($overlaps) / count($overlaps), min($overlaps)));
        $this->stdout($correlations === []
            ? "Rank correlation: no query had two shared results.\n"
            : sprintf("Rank correlation (Spearman): mean %+.2f, min %+.2f\n",
                array_sum($correlations) / count($correlations), min($correlations)));

        foreach ($phaseSamples as $arm => $phases) {
            if ($phases === []) {
                continue;
            }
            $this->stdout("\n{$arm}\n", Console::BOLD);
            $this->stdout(sprintf("  %-30s %9s %9s %5s\n", 'phase', 'p50 ms', 'p95 ms', 'n'));
            /* Total first, then the phases inside it, slowest first. */
            uasort($phases, static fn(array $a, array $b) => self::percentile($b, 50) <=> self::percentile($a, 50));
            foreach ($phases as $name => $samples) {
                $this->stdout(sprintf("  %-30s %9.1f %9.1f %5d\n",
                    $name, self::percentile($samples, 50), self::percentile($samples, 95), count($samples)));
            }
            $peaks = $memoryPeaks[$arm];
            if ($peaks !== []) {
                $this->stdout('  peak memory: ' . self::formatBytes((int)max($peaks)) . "\n");
            }
        }

        $this->stdout("\nOverlap is not a score: the two types are meant to differ. Read it with the\n");
        $this->stdout("per-query lists above, where a disagreement is attributable to a query.\n");
        $this->stdout("\nThe p95 columns are pessimistic. Both engines run in this one process, and the\n");
        $this->stdout("pgvector arm holds persistent handles and in-flight prefetches throughout, so the\n");
        $this->stdout("tails interleave. Measured one engine per process, local ranking is p50 33 ms /\n");
        $this->stdout("p95 57 ms against pgvector's 149 ms / 158 ms. Trust p50 here, or measure each\n");
        $this->stdout("engine on its own for tail numbers.\n");

        return ExitCode::OK;
    }

    /**
     * Set overlap of the two result lists, ignoring order.
     *
     * @param int[] $a
     * @param int[] $b
     */
    private static function jaccard(array $a, array $b): float
    {
        $union = count(array_unique(array_merge($a, $b)));

        return $union === 0 ? 0.0 : count(array_intersect($a, $b)) / $union;
    }

    /**
     * Spearman's rho over the entries both types returned, so it measures whether they
     * agree on the order of what they share. Null below two shared results, where a
     * correlation has no meaning.
     *
     * @param int[] $a
     * @param int[] $b
     */
    private static function spearman(array $a, array $b): ?float
    {
        $shared = array_values(array_intersect($a, $b));
        $n = count($shared);
        if ($n < 2) {
            return null;
        }

        /*
         * Re-ranked 1..n within the shared set, in each list's own order. Using the
         * positions from the full lists instead puts rho outside [-1, 1], because the
         * formula's denominator assumes the ranks are a permutation of 1..n.
         */
        $rankA = array_flip(array_values(array_intersect($a, $shared)));
        $rankB = array_flip(array_values(array_intersect($b, $shared)));

        $sumSquaredDifference = 0;
        foreach ($shared as $id) {
            $difference = $rankA[$id] - $rankB[$id];
            $sumSquaredDifference += $difference * $difference;
        }

        return 1 - (6 * $sumSquaredDifference) / ($n * ($n * $n - 1));
    }

    /**
     * Nearest-rank percentile.
     *
     * @param float[] $samples
     */
    private static function percentile(array $samples, int $percentile): float
    {
        if ($samples === []) {
            return 0.0;
        }

        sort($samples);
        $index = (int)ceil(($percentile / 100) * count($samples)) - 1;

        return $samples[max(0, min($index, count($samples) - 1))];
    }

    private static function formatBytes(int $bytes): string
    {
        return Craft::$app->getFormatter()->asShortSize($bytes, 1);
    }
}

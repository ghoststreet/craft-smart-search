<?php

namespace ghoststreet\craftsmartsearch\jobs;

use Craft;
use craft\base\Batchable;
use craft\db\QueryBatcher;
use craft\elements\Entry;
use craft\i18n\Translation;
use craft\queue\BaseBatchedJob;
use ghoststreet\craftsmartsearch\helpers\Logger;
use ghoststreet\craftsmartsearch\SmartSearch;
use Throwable;

/**
 * Walks every enabled entry that has a URI and re-indexes those whose extracted text
 * hash has changed, into the local store. Unchanged entries short-circuit inside
 * LocalIndexService::syncEntry, so the per-item cost is one read of the stored hash.
 *
 * The local twin of SyncSearchIndexJob, deliberately not a mode of it: the two stores
 * are reindexed independently, so one can be rebuilt without re-embedding for the other.
 */
class LocalSyncIndexJob extends BaseBatchedJob
{
    /**
     * High enough that neighbouring bad entries cannot trip it, low enough that a cause
     * affecting every entry fails immediately rather than grinding through the site.
     */
    private const FAILURE_ABORT_THRESHOLD = 5;

    public ?int $siteId = null;
    public ?string $section = null;

    private int $consecutiveFailures = 0;

    protected function loadData(): Batchable
    {
        $query = Entry::find()
            ->siteId($this->siteId ?? '*')
            ->unique(false)
            ->status(Entry::STATUS_ENABLED)
            ->uri(':notempty:')
            ->select(['elements.id', 'elements_sites.siteId'])
            ->orderBy(['elements.id' => SORT_ASC, 'elements_sites.siteId' => SORT_ASC])
            ->asArray();

        if ($this->section !== null) {
            $query->section($this->section);
        }

        return new QueryBatcher($query);
    }

    protected function processItem(mixed $item): void
    {
        $entryId = (int)$item['id'];
        $siteId = (int)$item['siteId'];

        $entry = Entry::find()
            ->id($entryId)
            ->siteId($siteId)
            ->status(null)
            ->one();

        if (!$entry) {
            Logger::debug('Local sync skipped: entry vanished mid-run', ['entryId' => $entryId, 'siteId' => $siteId]);
            return;
        }

        if ($entry->getStatus() === Entry::STATUS_DISABLED) {
            SmartSearch::getInstance()->localIndexService->deleteForEntry($entryId, $siteId);
            return;
        }

        /*
         * One unindexable entry skips that entry. A run of them aborts the job.
         *
         * Both halves are load-bearing. Catching everything and continuing hides a cause
         * that applies to every remaining entry, such as a missing API key: the run then
         * skips the whole site and still reports success. Rethrowing everything lets one
         * entry the database will not store abort the reindex at the same item on every
         * retry, leaving the rest unindexed.
         *
         * Consecutive failures are the distinction: they mean the cause is the run and not
         * the entry. The counter resets on any success, so scattered bad entries never
         * trip it.
         */
        try {
            SmartSearch::getInstance()->localIndexService->syncEntry($entry);
            $this->consecutiveFailures = 0;
        } catch (Throwable $e) {
            $this->consecutiveFailures++;

            if ($this->consecutiveFailures >= self::FAILURE_ABORT_THRESHOLD) {
                Logger::error('Local sync aborted: {count} entries failed in a row, so the cause is not the entry', [
                    'count' => $this->consecutiveFailures,
                    'entryId' => $entryId,
                    'siteId' => $siteId,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }

            Logger::error('Local sync skipped one entry and continued', [
                'entryId' => $entryId,
                'siteId' => $siteId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function defaultDescription(): ?string
    {
        if ($this->siteId !== null) {
            $site = Craft::$app->getSites()->getSiteById($this->siteId);
            $label = $site?->name ?? "site {$this->siteId}";
            return Translation::prep('smart-search', 'Syncing the local Smart Search index: {name}', [
                'name' => $label,
            ]);
        }
        return Translation::prep('smart-search', 'Syncing the local Smart Search index');
    }
}

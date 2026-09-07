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
    public ?int $siteId = null;
    public ?string $section = null;

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
         * Deliberately not wrapped in a catch, matching SyncSearchIndexJob.
         *
         * It used to swallow every Throwable so one unembeddable entry could not abandon
         * the run. The failure it actually caught was a missing API key, which fails for
         * every remaining entry too: one run logged the same error 962 times across 481
         * entries, skipped them all, and reported success. Twelve live pages were still
         * missing from the local index months later while pgvector held them, because
         * pgvector's job lets the exception through and Craft's queue retries it.
         *
         * An index that quietly omits pages is worse than a job that fails loudly.
         */
        SmartSearch::getInstance()->localIndexService->syncEntry($entry);
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

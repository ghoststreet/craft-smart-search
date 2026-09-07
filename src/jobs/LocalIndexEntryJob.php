<?php

namespace ghoststreet\craftsmartsearch\jobs;

use craft\elements\Entry;
use craft\i18n\Translation;
use craft\queue\BaseJob;
use ghoststreet\craftsmartsearch\helpers\Logger;
use ghoststreet\craftsmartsearch\SmartSearch;
use Throwable;

/**
 * Queue job to index a single entry into the local store.
 *
 * A separate job from IndexEntryJob rather than a step inside it: the two indexes are
 * isolated, so a local failure must not fail or delay the pgvector index of the same
 * entry. That is also why nothing here throws — a failed local index is logged and the
 * job completes, since the pgvector store remains authoritative until the comparison
 * says otherwise.
 */
class LocalIndexEntryJob extends BaseJob
{
    public int $entryId;
    public int $siteId;

    public function execute($queue): void
    {
        if (!SmartSearch::getInstance()->getSettings()->localEnabled) {
            return;
        }

        try {
            $entry = Entry::find()
                ->id($this->entryId)
                ->siteId($this->siteId)
                ->status(null)
                ->one();

            if (!$entry) {
                Logger::debug('Local index skipped: entry no longer exists', [
                    'entryId' => $this->entryId,
                    'siteId' => $this->siteId,
                ]);
                return;
            }

            /* Status may have flipped between enqueue and execution. */
            if ($entry->getStatus() === Entry::STATUS_DISABLED) {
                SmartSearch::getInstance()->localIndexService->deleteForEntry($this->entryId, $this->siteId);
                return;
            }

            SmartSearch::getInstance()->localIndexService->syncEntry($entry);
        } catch (Throwable $e) {
            Logger::exception($e, 'localIndexEntryJob', [
                'entryId' => $this->entryId,
                'siteId' => $this->siteId,
            ]);
        }
    }

    protected function defaultDescription(): ?string
    {
        return Translation::prep('smart-search', 'Smart Search: local indexing entry #{id}', [
            'id' => $this->entryId,
        ]);
    }
}

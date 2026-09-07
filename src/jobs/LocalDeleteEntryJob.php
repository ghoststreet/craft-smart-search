<?php

namespace ghoststreet\craftsmartsearch\jobs;

use craft\i18n\Translation;
use craft\queue\BaseJob;
use ghoststreet\craftsmartsearch\helpers\Logger;
use ghoststreet\craftsmartsearch\SmartSearch;
use Throwable;

/**
 * Queue job to remove a single entry from the local store.
 *
 * Runs even when the local type is disabled: leaving rows behind for an entry Craft has
 * deleted would resurrect it the moment the type is switched back on.
 */
class LocalDeleteEntryJob extends BaseJob
{
    public int $entryId;
    public int $siteId;

    public function execute($queue): void
    {
        try {
            SmartSearch::getInstance()->localIndexService->deleteForEntry($this->entryId, $this->siteId);
        } catch (Throwable $e) {
            Logger::exception($e, 'localDeleteEntryJob', [
                'entryId' => $this->entryId,
                'siteId' => $this->siteId,
            ]);
        }
    }

    protected function defaultDescription(): ?string
    {
        return Translation::prep('smart-search', 'Smart Search: removing entry #{id} from the local index', [
            'id' => $this->entryId,
        ]);
    }
}

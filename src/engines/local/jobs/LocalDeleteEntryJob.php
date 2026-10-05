<?php

namespace ghoststreet\craftsmartsearch\engines\local\jobs;

use craft\i18n\Translation;
use craft\queue\BaseJob;
use ghoststreet\craftsmartsearch\engines\local\LocalIndexService;

/**
 * Queue job to remove a single entry from the local store.
 */
class LocalDeleteEntryJob extends BaseJob
{
    public int $entryId;
    public int $siteId;

    public function execute($queue): void
    {
        LocalIndexService::instance()->deleteForEntry($this->entryId, $this->siteId);
    }

    protected function defaultDescription(): ?string
    {
        return Translation::prep('smart-search', 'Smart Search: removing entry #{id} from the local index', [
            'id' => $this->entryId,
        ]);
    }
}

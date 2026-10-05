<?php

namespace ghoststreet\craftsmartsearch\engines\local\jobs;

use craft\i18n\Translation;
use craft\queue\BaseJob;
use ghoststreet\craftsmartsearch\engines\local\LocalIndexService;

/**
 * Queue job to index a single entry, or prune it when it is disabled.
 */
class LocalIndexEntryJob extends BaseJob
{
    public int $entryId;
    public int $siteId;

    public function execute($queue): void
    {
        LocalIndexService::instance()->syncOrPrune($this->entryId, $this->siteId);
    }

    protected function defaultDescription(): ?string
    {
        return Translation::prep('smart-search', 'Smart Search: local indexing entry #{id}', [
            'id' => $this->entryId,
        ]);
    }
}

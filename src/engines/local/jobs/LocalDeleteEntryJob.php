<?php

namespace ghoststreet\craftsmartsearch\engines\local\jobs;

use craft\i18n\Translation;
use craft\queue\BaseJob;
use ghoststreet\craftsmartsearch\engines\local\LocalIndexService;

/**
 * Queue job to remove entries on one site from the local store.
 */
class LocalDeleteEntryJob extends BaseJob
{
    /** @var list<int> */
    public array $entryIds;
    public int $siteId;

    public function execute($queue): void
    {
        LocalIndexService::instance()->deleteForEntries($this->entryIds, $this->siteId);
    }

    protected function defaultDescription(): ?string
    {
        return count($this->entryIds) === 1
            ? Translation::prep('smart-search', 'Smart Search: removing entry #{id} from the local index', ['id' => $this->entryIds[0]])
            : Translation::prep('smart-search', 'Smart Search: removing {count} entries from the local index', ['count' => count($this->entryIds)]);
    }
}

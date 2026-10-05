<?php

namespace ghoststreet\craftsmartsearch\engines\local\jobs;

use craft\i18n\Translation;
use craft\queue\BaseJob;
use ghoststreet\craftsmartsearch\engines\BaseEngine;
use ghoststreet\craftsmartsearch\engines\local\LocalContextVectors;

/**
 * Separate from the index sync because it is a corpus-wide pass: a term's meaning here is the
 * company it keeps across every chunk, so it cannot be known until the last entry is indexed.
 * The sync queues this on completion.
 */
class LocalBuildVectorsJob extends BaseJob
{
    public ?int $siteId = null;

    public function execute($queue): void
    {
        $this->setProgress($queue, 0);

        LocalContextVectors::instance()->rebuild($this->siteId);

        $this->setProgress($queue, 1);
    }

    protected function defaultDescription(): ?string
    {
        if ($this->siteId !== null) {
            return Translation::prep('smart-search', 'Learning related terms from your content: {name}', [
                'name' => BaseEngine::siteName($this->siteId),
            ]);
        }

        return Translation::prep('smart-search', 'Learning related terms from your content');
    }
}

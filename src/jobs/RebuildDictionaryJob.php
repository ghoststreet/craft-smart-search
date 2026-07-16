<?php

namespace ghoststreet\craftsmartsearch\jobs;

use craft\i18n\Translation;
use craft\queue\BaseJob;
use ghoststreet\craftsmartsearch\SmartSearch;

class RebuildDictionaryJob extends BaseJob
{
    public function execute($queue): void
    {
        $dictionary = SmartSearch::getInstance()->dictionaryService;

        $this->setProgress($queue, 0.1, 'Ensuring dictionary schema');
        $dictionary->ensureSchema();

        $this->setProgress($queue, 0.4, 'Sampling corpus lexemes');
        $dictionary->rebuild();
    }

    protected function defaultDescription(): ?string
    {
        return Translation::prep('smart-search', 'Rebuilding Smart Search typo-tolerance dictionary');
    }
}

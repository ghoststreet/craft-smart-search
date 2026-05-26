<?php

namespace ghoststreet\craftsmartsearch\jobs;

use craft\i18n\Translation;
use craft\queue\BaseJob;
use ghoststreet\craftsmartsearch\helpers\Logger;
use ghoststreet\craftsmartsearch\SmartSearch;

/**
 * Rebuilds the typo-tolerance dictionary from the indexed content.
 *
 * Cheap on small corpora (one `ts_stat` scan + a TRUNCATE/INSERT), so it can
 * be queued after batch reindexes without rate-limiting. Failure is logged and
 * swallowed — the corrector falls back to its unavailable path.
 */
class RebuildDictionaryJob extends BaseJob
{
    public function execute($queue): void
    {
        $dictionary = SmartSearch::getInstance()->dictionaryService;

        // Clear early so any entry change that comes in mid-rebuild is allowed
        // to schedule the next rebuild — otherwise edits during a long rebuild
        // could be missed until the coalesce TTL expires.
        $dictionary->clearRebuildPendingMarker();

        $this->setProgress($queue, 0.1, 'Ensuring dictionary schema');
        $dictionary->ensureSchema();

        $this->setProgress($queue, 0.4, 'Sampling corpus lexemes');
        $rows = $dictionary->rebuild();

        if ($rows === null) {
            Logger::warning('Dictionary rebuild was skipped — see prior log entries for reason');
            return;
        }

        $this->setProgress($queue, 1.0, "Dictionary rebuilt ({$rows} terms)");
    }

    protected function defaultDescription(): ?string
    {
        return Translation::prep('smart-search', 'Rebuilding Smart Search typo-tolerance dictionary');
    }
}

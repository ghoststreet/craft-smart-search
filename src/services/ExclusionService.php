<?php

namespace ghoststreet\craftsmartsearch\services;

use craft\helpers\Db;
use ghoststreet\craftsmartsearch\helpers\Logger;
use ghoststreet\craftsmartsearch\records\ExcludedEntryRecord;
use ghoststreet\craftsmartsearch\SmartSearch;
use yii\base\Component;

/**
 * Tracks entries that have been manually excluded from the search index.
 *
 * An exclusion is a row in smart_search_excluded_entries. Each engine's index
 * service checks isExcluded() and skips (and prunes) excluded entries, so bulk
 * sync and entry-save events all respect exclusions automatically.
 */
class ExclusionService extends Component
{
    public function isExcluded(int $elementId, int $siteId): bool
    {
        return ExcludedEntryRecord::find()
            ->where(['elementId' => $elementId, 'siteId' => $siteId])
            ->exists();
    }

    /**
     * Exclude an entry: record the exclusion and remove its vectors immediately.
     */
    public function exclude(int $elementId, int $siteId): void
    {
        Db::upsert(ExcludedEntryRecord::tableName(), ['elementId' => $elementId, 'siteId' => $siteId], false);

        SmartSearch::getInstance()->engine()->queueDelete([$elementId], $siteId);
        Logger::info('Excluded entry from index', ['elementId' => $elementId, 'siteId' => $siteId]);
    }

    /**
     * Remove an entry's exclusion so it can be indexed again.
     */
    public function include(int $elementId, int $siteId): void
    {
        ExcludedEntryRecord::deleteAll(['elementId' => $elementId, 'siteId' => $siteId]);
        Logger::info('Re-included entry in index', ['elementId' => $elementId, 'siteId' => $siteId]);
    }

    /** Forget every exclusion on a site, for when the site itself is deleted. */
    public function clearSite(int $siteId): void
    {
        ExcludedEntryRecord::deleteAll(['siteId' => $siteId]);
    }

    /**
     * Return excluded entries as a set of "elementId-siteId" keys for cheap lookups.
     *
     * @return array<string, true>
     */
    public function getExcludedKeys(int $siteId): array
    {
        $rows = ExcludedEntryRecord::find()
            ->select(['elementId', 'siteId'])
            ->where(['siteId' => $siteId])
            ->asArray()
            ->all();

        $keys = [];
        foreach ($rows as $row) {
            $keys[$row['elementId'] . '-' . $row['siteId']] = true;
        }

        return $keys;
    }
}

<?php

namespace ghoststreet\craftsmartsearch\engines;

use Craft;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use craft\queue\BaseJob;
use ghoststreet\craftsmartsearch\SmartSearch;

/**
 * Queue plumbing every engine shares. Engines differ in their jobs, not in how those
 * jobs reach the queue, so each one names its three job classes and inherits the rest.
 */
abstract class BaseEngine implements SearchEngine
{
    /** @return class-string<BaseJob> */
    abstract protected function indexJobClass(): string;

    /** @return class-string<BaseJob> */
    abstract protected function deleteJobClass(): string;

    /** Every entry a search can return: enabled and in an indexed section, optionally one by handle, so Matrix blocks stay out. Callers add the site and select. */
    public static function indexableEntries(?string $section = null): EntryQuery
    {
        $sectionIds = SmartSearch::getInstance()->getSettings()->indexedSectionIds();

        if ($section !== null) {
            $sectionIds = array_values(array_intersect($sectionIds, [Craft::$app->getEntries()->getSectionByHandle($section)?->id]));
        }

        return Entry::find()->sectionId($sectionIds)->status(Entry::STATUS_ENABLED);
    }

    /** One entry on one site, whatever its status, so a disabled entry can be pruned. */
    public static function findEntry(int $entryId, int $siteId): ?Entry
    {
        return Entry::find()->id($entryId)->siteId($siteId)->status(null)->one();
    }

    /** The site's name, or its handle when the name is empty. */
    public static function siteName(int $siteId): string
    {
        $site = Craft::$app->getSites()->getSiteById($siteId);
        $name = trim($site?->name ?? '');

        return $name !== '' ? $name : ($site?->handle ?? "site {$siteId}");
    }

    /** The index job prunes the entry itself when it is disabled. */
    public function queueIndex(Entry $entry): ?string
    {
        $class = $this->indexJobClass();

        return (string)Craft::$app->getQueue()->push(new $class(['entryId' => $entry->id, 'siteId' => $entry->siteId]));
    }

    public function queueDelete(array $elementIds, int $siteId): void
    {
        if ($elementIds === []) {
            return;
        }

        $class = $this->deleteJobClass();
        Craft::$app->getQueue()->push(new $class(['entryIds' => $elementIds, 'siteId' => $siteId]));
    }

    public function queueSync(?int $siteId, ?string $section = null): void
    {
        $class = $this->syncJobClass();
        $siteIds = $siteId !== null ? [$siteId] : Craft::$app->getSites()->getAllSiteIds();

        foreach ($siteIds as $id) {
            Craft::$app->getQueue()->push(new $class(['siteId' => (int)$id, 'section' => $section]));
        }
    }
}

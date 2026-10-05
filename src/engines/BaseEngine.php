<?php

namespace ghoststreet\craftsmartsearch\engines;

use Craft;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use craft\queue\BaseJob;

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

    /** Every entry a search can return: enabled, with a URL. Callers add the site and select. */
    public static function indexableEntries(): EntryQuery
    {
        return Entry::find()->status(Entry::STATUS_ENABLED)->uri(':notempty:');
    }

    /** One entry on one site, whatever its status, so a disabled entry can be pruned. */
    public static function findEntry(int $entryId, int $siteId): ?Entry
    {
        return Entry::find()->id($entryId)->siteId($siteId)->status(null)->one();
    }

    /** A site's name can be an empty string (an unset env var), which is no use as a label. */
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

    public function queueDelete(int $elementId, int $siteId): void
    {
        $class = $this->deleteJobClass();
        Craft::$app->getQueue()->push(new $class(['entryId' => $elementId, 'siteId' => $siteId]));
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

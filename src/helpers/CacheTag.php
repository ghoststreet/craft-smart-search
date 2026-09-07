<?php

namespace ghoststreet\craftsmartsearch\helpers;

use Craft;
use yii\caching\TagDependency;

/**
 * Tags every cache entry the plugin writes, so uninstalling leaves none behind.
 *
 * They live in Craft's shared cache, which has no notion of which plugin owns a key and
 * no delete-by-prefix on any backend, so without the tag they simply cannot be found
 * again. Left behind, a reinstall reads embeddings, rankings and capability probes
 * belonging to an index and a database that are gone.
 *
 * Pass dependency() as the last argument of every set() and getOrSet().
 */
final class CacheTag
{
    public const TAG = 'smart-search';

    public static function dependency(): TagDependency
    {
        return new TagDependency(['tags' => self::TAG]);
    }

    public static function invalidate(): void
    {
        TagDependency::invalidate(Craft::$app->getCache(), self::TAG);
    }
}

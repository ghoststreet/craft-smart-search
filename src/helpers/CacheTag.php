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

    /**
     * Tags Craft's own caches that hold search results, which today means GraphQL responses.
     * Craft only clears those when elements change, so without this a response cached
     * while an entry was still waiting to be indexed, or under old ranking settings,
     * would keep being served.
     */
    public const RESULTS = 'smart-search:results';

    public static function dependency(): TagDependency
    {
        return new TagDependency(['tags' => self::TAG]);
    }

    public static function invalidate(): void
    {
        TagDependency::invalidate(Craft::$app->getCache(), self::TAG);
    }

    /** Call while Craft is collecting cache tags (a GraphQL query) for anything built from search results. */
    public static function collectResults(): void
    {
        Craft::$app->getElements()->collectCacheTags([self::RESULTS]);
    }

    /** Call whenever what a search returns may have changed. */
    public static function invalidateResults(): void
    {
        TagDependency::invalidate(Craft::$app->getCache(), self::RESULTS);
    }

    /**
     * Opaque token for a store's current contents, stored under $key. Anything derived from
     * that store keys on it, so deleting the key invalidates those entries immediately
     * rather than leaving them to expire.
     *
     * Random rather than a counter, so an evicted key cannot resurrect entries cached
     * under an earlier value.
     */
    public static function token(string $key): string
    {
        $cache = Craft::$app->getCache();
        $token = $cache->get($key);

        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(8));
            $cache->set($key, $token, 0, self::dependency());
        }

        return $token;
    }
}

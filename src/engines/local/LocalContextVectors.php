<?php

namespace ghoststreet\craftsmartsearch\engines\local;

use Craft;
use craft\db\Query;
use ghoststreet\craftsmartsearch\helpers\CacheTag;
use ghoststreet\craftsmartsearch\helpers\FieldPrefix;
use ghoststreet\craftsmartsearch\helpers\Logger;
use ghoststreet\craftsmartsearch\helpers\Stemmer;
use ghoststreet\craftsmartsearch\SmartSearch;
use Throwable;

/**
 * A meaning signal built from the site's own content, with no API and no model file.
 *
 * Random Indexing. Each term gets a sparse random vector derived from a hash, so it is never
 * stored. A term's context vector is the sum, over every chunk it appears in, of the random
 * vectors of the other terms there, so terms that keep the same company end up pointing the
 * same way: "bedroom" and "bathroom" converge on a property site because the same third words
 * surround both, even though neither predicts the other.
 *
 * Chunk and query vectors are the tf-idf weighted sum of their terms' context vectors:
 * ordinary unit float32, so they pass through pack(), the vector scan and the RRF
 * fusion unchanged.
 *
 * Not a language model. It knows only what one site puts side by side, so it will not learn
 * that "car" means "automobile" from a site that never writes both. Hence "related terms"
 * rather than "semantic", and hence a provider key still being an upgrade.
 */
class LocalContextVectors
{
    private static ?self $instance = null;

    /** Engine-internal accessor: these services are not plugin components. */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /** Stamped into the vectors table's model column, so a source switch invalidates. */
    public const HANDLE = 'local-ri';

    public const LABEL = 'Related terms';

    /** Below this, co-occurrence is noise, and a noisy second signal ranks worse than none. */
    private const MIN_CORPUS_CHUNKS = 200;

    private const NON_ZEROS = 8;

    /**
     * ponytail: one in-memory accumulator, so this bounds peak memory during a rebuild:
     * vocabulary is capped at this divided by the width, and 4M slots is roughly 64MB of PHP
     * floats. Upgrade is banded passes with a partial flush between bands: flat memory, more
     * queries.
     */
    private const ACCUMULATOR_SLOTS = 4000000;

    private const MIN_DOCUMENT_FREQUENCY = 2;

    private const MAX_DOCUMENT_SHARE = 0.5;

    /** Chunk rows per keyset page, and composite keys per IN list. */
    private const BATCH_SIZE = 200;

    private const HAS_VECTORS_CACHE_PREFIX = 'smart_search_local_ctx_';

    private const HAS_VECTORS_CACHE_TTL_SECONDS = 300;

    /** @var array<string, array<int, int>> memoised index vectors, keyed "siteId|term" */
    private array $indexVectorCache = [];

    /**
     * Rebuild the context vectors and every chunk vector derived from them.
     *
     * Both passes run here because they share the accumulator: building it is the expensive
     * part, and reading it straight back out of the database would be waste.
     *
     * @return int Terms modelled
     */
    public function rebuild(?int $siteId = null): int
    {
        if (!SmartSearch::getInstance()->vectorSources->isLocal()) {
            return 0;
        }

        $total = 0;

        foreach ($this->siteIds($siteId) as $site) {
            $total += $this->rebuildSite($site);
        }

        LocalIndexService::instance()->bumpCacheToken();

        return $total;
    }

    private function rebuildSite(int $siteId): int
    {
        $dimensions = SmartSearch::getInstance()->getSettings()->dimensions;

        $corpusSize = $this->corpusSize($siteId);

        if ($corpusSize < self::MIN_CORPUS_CHUNKS) {
            $this->clearSite($siteId);
            Logger::info('Corpus too small to model related terms; keyword scoring only', [
                'siteId' => $siteId,
                'chunks' => $corpusSize,
                'required' => self::MIN_CORPUS_CHUNKS,
            ]);

            return 0;
        }

        $vocabulary = $this->vocabulary($siteId, $corpusSize, $dimensions);

        if (count($vocabulary) < 2) {
            $this->clearSite($siteId);

            return 0;
        }

        $context = $this->accumulate($siteId, $vocabulary, $dimensions);

        foreach ($context as $term => $vector) {
            $context[$term] = LocalIndexService::normalise($vector);
        }

        $this->persistContext($siteId, $context);
        $written = $this->revectoriseChunks($siteId, $vocabulary, $context, $dimensions);

        Logger::info('Rebuilt the local context vectors', [
            'siteId' => $siteId,
            'chunks' => $corpusSize,
            'terms' => count($context),
            'vectors' => $written,
            'dims' => $dimensions,
        ]);

        return count($context);
    }

    /**
     * Both ends of the frequency range are cut for the reason BM25's idf implies: a term in
     * one chunk has no company to learn from, one in half the corpus keeps company with
     * everything.
     *
     * @return array<string, float> term => idf
     */
    private function vocabulary(int $siteId, int $corpusSize, int $dimensions): array
    {
        $ceiling = max(self::MIN_DOCUMENT_FREQUENCY, (int)floor($corpusSize * self::MAX_DOCUMENT_SHARE));
        $limit = max(500, intdiv(self::ACCUMULATOR_SLOTS, max(1, $dimensions)));

        $rows = (new Query())
            ->select(['term', 'df'])
            ->from(LocalSchema::TERMS_TABLE)
            ->where(['siteId' => $siteId])
            ->andWhere(['>=', 'df', self::MIN_DOCUMENT_FREQUENCY])
            ->andWhere(['<=', 'df', $ceiling])
            ->orderBy(['df' => SORT_DESC])
            ->limit($limit)
            ->all();

        $vocabulary = [];
        foreach ($rows as $row) {
            $vocabulary[(string)$row['term']] = self::idf($corpusSize, (int)$row['df']);
        }

        return $vocabulary;
    }

    /**
     * The chunk's summed index vector is computed once and added to every term in it, then
     * each term's own contribution subtracted back out. The naive reading of "sum of the other
     * terms' vectors" is quadratic in chunk vocabulary and does not finish on a real corpus;
     * this is the same arithmetic in linear time.
     *
     * @param array<string, float> $vocabulary term => idf
     * @return array<string, array<int, float>>
     */
    private function accumulate(int $siteId, array $vocabulary, int $dimensions): array
    {
        $context = [];

        foreach ($this->chunkKeyBatches($siteId) as $keys) {
            $grouped = [];

            $postings = (new Query())
                ->select(['elementId', 'chunkIndex', 'term'])
                ->from(LocalSchema::POSTINGS_TABLE)
                ->where(['siteId' => $siteId])
                ->andWhere(['in', ['elementId', 'chunkIndex'], $keys])
                ->all();

            foreach ($postings as $row) {
                $term = (string)$row['term'];
                if (isset($vocabulary[$term])) {
                    $grouped[$row['elementId'] . '-' . $row['chunkIndex']][$term] = true;
                }
            }

            foreach ($grouped as $chunkTerms) {
                $this->foldChunk($siteId, $chunkTerms, $vocabulary, $dimensions, $context);
            }
        }

        return $context;
    }

    /**
     * Keyed by column name, not positional: the composite-IN builder reads each row by column
     * and silently emits NULLs for a plain list.
     *
     * @return iterable<list<array{elementId: int, chunkIndex: int}>>
     */
    private function chunkKeyBatches(int $siteId): iterable
    {
        $query = (new Query())
            ->select(['id', 'elementId', 'chunkIndex'])
            ->from(LocalSchema::CHUNKS_TABLE)
            ->where(['siteId' => $siteId]);

        foreach (LocalSchema::batches($query, self::BATCH_SIZE) as $rows) {
            yield array_map(static fn(array $row): array => [
                'elementId' => (int)$row['elementId'],
                'chunkIndex' => (int)$row['chunkIndex'],
            ], $rows);
        }
    }

    /**
     * This and indexVector() are the whole model; everything else here is queries around them.
     *
     * @param array<string, true> $chunkTerms
     * @param array<string, float> $vocabulary
     * @param array<string, array<int, float>> $context
     */
    private function foldChunk(int $siteId, array $chunkTerms, array $vocabulary, int $dimensions, array &$context): void
    {
        if (count($chunkTerms) < 2) {
            return;
        }

        $chunkSum = array_fill(0, $dimensions, 0.0);

        foreach (array_keys($chunkTerms) as $term) {
            $weight = $vocabulary[$term];
            foreach ($this->indexVector($siteId, $term, $dimensions) as $position => $sign) {
                $chunkSum[$position] += $sign * $weight;
            }
        }

        foreach (array_keys($chunkTerms) as $term) {
            if (!isset($context[$term])) {
                $context[$term] = array_fill(0, $dimensions, 0.0);
            }

            $vector = &$context[$term];

            foreach ($chunkSum as $i => $value) {
                $vector[$i] += $value;
            }

            $weight = $vocabulary[$term];
            foreach ($this->indexVector($siteId, $term, $dimensions) as $position => $sign) {
                $vector[$position] -= $sign * $weight;
            }

            unset($vector);
        }
    }

    /**
     * NON_ZEROS positions carrying +1 or -1, everything else zero. Derived from a hash rather
     * than stored: no matrix to keep, no training step, and two machines agree. Their
     * near-orthogonality is what lets the sums stay separable.
     *
     * @return array<int, int> position => sign
     */
    private function indexVector(int $siteId, string $term, int $dimensions): array
    {
        $key = $siteId . '|' . $term;

        if (isset($this->indexVectorCache[$key])) {
            return $this->indexVectorCache[$key];
        }

        $digest = hash('sha256', $key, true);
        $words = array_values(unpack('N*', $digest));

        $vector = [];
        for ($i = 0; $i < self::NON_ZEROS; $i++) {
            $word = $words[$i];
            $position = $word % $dimensions;
            $sign = ($word & 0x80000000) !== 0 ? -1 : 1;
            $vector[$position] = ($vector[$position] ?? 0) + $sign;
        }

        return $this->indexVectorCache[$key] = array_filter($vector, static fn(int $s): bool => $s !== 0);
    }

    /**
     * @param array<string, array<int, float>> $context
     */
    private function persistContext(int $siteId, array $context): void
    {
        $db = Craft::$app->getDb();

        $transaction = $db->beginTransaction();

        try {
            $db->createCommand()->update(LocalSchema::TERMS_TABLE, ['vector' => null], ['siteId' => $siteId])->execute();

            foreach ($context as $term => $vector) {
                $db->createCommand()->update(
                    LocalSchema::TERMS_TABLE,
                    ['vector' => LocalIndexService::pack($vector)],
                    ['siteId' => $siteId, 'term' => (string)$term],
                )->execute();
            }

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    /**
     * @param array<string, float> $vocabulary
     * @param array<string, array<int, float>> $context
     * @return int Vectors written
     */
    private function revectoriseChunks(int $siteId, array $vocabulary, array $context, int $dimensions): int
    {
        $db = Craft::$app->getDb();
        $written = 0;
        $stale = [];

        $query = (new Query())
            ->select(['id', 'elementId', 'chunkIndex', 'sectionId', 'title', 'body', 'language'])
            ->from(LocalSchema::CHUNKS_TABLE)
            ->where(['siteId' => $siteId]);

        foreach (LocalSchema::batches($query, self::BATCH_SIZE) as $rows) {
            foreach ($rows as $row) {
                $text = trim((string)$row['title'] . "\n\n" . FieldPrefix::forEmbedding((string)$row['body']));
                $vector = $this->compose(
                    $this->weights($text, (string)$row['language'], $vocabulary),
                    $context,
                    $dimensions,
                );

                if ($vector === []) {
                    $stale[] = ['elementId' => (int)$row['elementId'], 'chunkIndex' => (int)$row['chunkIndex']];
                    continue;
                }

                $db->createCommand()->upsert(LocalSchema::VECTORS_TABLE, [
                    'elementId' => (int)$row['elementId'],
                    'siteId' => $siteId,
                    'chunkIndex' => (int)$row['chunkIndex'],
                    'sectionId' => $row['sectionId'] !== null ? (int)$row['sectionId'] : null,
                    'dims' => $dimensions,
                    'model' => self::HANDLE,
                    'vector' => LocalIndexService::pack($vector),
                ])->execute();

                $written++;
            }
        }

        foreach (array_chunk($stale, self::BATCH_SIZE) as $batch) {
            $db->createCommand()->delete(LocalSchema::VECTORS_TABLE, [
                'and',
                ['siteId' => $siteId],
                ['in', ['elementId', 'chunkIndex'], $batch],
            ])->execute();
        }

        return $written;
    }

    /**
     * Read from the stored context vectors. The rebuild path does not come through here: it
     * holds the context in memory already.
     *
     * @return float[] Empty when nothing in the text is modelled
     */
    public function vectorFor(string $text, int $siteId): array
    {
        $dimensions = SmartSearch::getInstance()->getSettings()->dimensions;
        $language = Stemmer::resolveLanguage($siteId);

        $counts = $this->termCounts($text, $language);
        if ($counts === []) {
            return [];
        }

        $rows = (new Query())
            ->select(['term', 'df', 'vector'])
            ->from(LocalSchema::TERMS_TABLE)
            ->where(['siteId' => $siteId, 'term' => array_map('strval', array_keys($counts))])
            ->andWhere(['not', ['vector' => null]])
            ->all();

        if ($rows === []) {
            return [];
        }

        $corpusSize = $this->corpusSize($siteId);
        if ($corpusSize === 0) {
            return [];
        }

        $weights = [];
        $context = [];

        foreach ($rows as $row) {
            $term = (string)$row['term'];
            if (!isset($counts[$term])) {
                continue;
            }

            $unpacked = LocalIndexService::unpack((string)$row['vector']);
            if (count($unpacked) !== $dimensions) {
                continue;
            }

            $weights[$term] = $counts[$term] * self::idf($corpusSize, (int)$row['df']);
            $context[$term] = array_values($unpacked);
        }

        return $this->compose($weights, $context, $dimensions);
    }

    /**
     * @param array<string, float> $weights term => tf-idf weight
     * @param array<string, array<int, float>> $context
     * @return float[]
     */
    private function compose(array $weights, array $context, int $dimensions): array
    {
        $sum = array_fill(0, $dimensions, 0.0);
        $matched = 0;

        foreach ($weights as $term => $weight) {
            $vector = $context[$term] ?? null;
            if ($vector === null) {
                continue;
            }

            foreach ($vector as $i => $value) {
                $sum[$i] += $value * $weight;
            }

            $matched++;
        }

        if ($matched === 0) {
            return [];
        }

        return LocalIndexService::normalise($sum);
    }

    /**
     * @param array<string, float> $vocabulary term => idf
     * @return array<string, float>
     */
    private function weights(string $text, string $language, array $vocabulary): array
    {
        $weights = [];

        foreach ($this->termCounts($text, $language) as $term => $count) {
            if (isset($vocabulary[$term])) {
                $weights[$term] = $count * $vocabulary[$term];
            }
        }

        return $weights;
    }

    /**
     * The same treatment the postings get, so a query is measured in the terms the corpus is
     * stored in.
     *
     * @return array<string, int>
     */
    private function termCounts(string $text, string $language): array
    {
        $counts = [];

        foreach (Stemmer::terms($text, $language) as [, $stem]) {
            $counts[$stem] = ($counts[$stem] ?? 0) + 1;
        }

        return $counts;
    }

    public function isModelled(int $siteId): bool
    {
        $key = self::HAS_VECTORS_CACHE_PREFIX . md5(
            LocalIndexService::instance()->cacheToken() . '|' . $siteId
        );

        return (bool)Craft::$app->getCache()->getOrSet(
            $key,
            fn() => (new Query())
                ->from(LocalSchema::TERMS_TABLE)
                ->where(['siteId' => $siteId])
                ->andWhere(['not', ['vector' => null]])
                ->exists() ? 1 : 0,
            self::HAS_VECTORS_CACHE_TTL_SECONDS,
            CacheTag::dependency(),
        );
    }

    private function clearSite(int $siteId): void
    {
        $db = Craft::$app->getDb();
        $db->createCommand()->update(LocalSchema::TERMS_TABLE, ['vector' => null], ['siteId' => $siteId])->execute();
        $db->createCommand()->delete(LocalSchema::VECTORS_TABLE, ['siteId' => $siteId, 'model' => self::HANDLE])->execute();
    }

    private function corpusSize(int $siteId): int
    {
        return (int)(new Query())
            ->from(LocalSchema::CHUNKS_TABLE)
            ->where(['siteId' => $siteId])
            ->count();
    }

    /** BM25's idf, so one notion of term importance serves both signals. */
    public static function idf(int $corpusSize, int $documentFrequency): float
    {
        $df = max(1, $documentFrequency);

        return log(($corpusSize - $df + 0.5) / ($df + 0.5) + 1);
    }

    /** @return list<int> */
    private function siteIds(?int $siteId): array
    {
        return $siteId !== null ? [$siteId] : Craft::$app->getSites()->getAllSiteIds();
    }
}

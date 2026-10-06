<?php

namespace ghoststreet\craftsmartsearch\engines\local;

use Craft;
use craft\db\Query;
use ghoststreet\craftsmartsearch\embeddings\VectorSource;
use ghoststreet\craftsmartsearch\helpers\CacheTag;
use ghoststreet\craftsmartsearch\helpers\Logger;
use ghoststreet\craftsmartsearch\helpers\Ranker;
use ghoststreet\craftsmartsearch\helpers\Stemmer;
use ghoststreet\craftsmartsearch\helpers\TimingProfiler;
use ghoststreet\craftsmartsearch\helpers\TypoCorrector;
use ghoststreet\craftsmartsearch\models\Settings;
use ghoststreet\craftsmartsearch\SmartSearch;
use SplMinHeap;
use yii\db\Expression;

/**
 * Hybrid semantic and keyword search, scored against Craft's own database.
 *
 * Semantic retrieval is an exact cosine scan in PHP over every stored vector. Keyword
 * scoring is BM25 over an inverted index, typo correction is PHP's levenshtein() over a
 * length-banded dictionary lookup, and boost rules are matched by phrase adjacency over
 * the query's lexeme positions.
 *
 * Fusion, the boost merge and element hydration are Ranker's, so ranking lives in one
 * place no matter what supplies the candidates.
 */
class LocalSearchService
{
    private const RANKING_CACHE_KEY_PREFIX = 'smart_search_local_ranking_';

    private const VARIANT_CACHE_TTL_SECONDS = 2592000;

    private const VARIANT_CACHE_KEY_PREFIX = 'smart_search_local_variant_';

    /** A correction scores at half weight, so a wrong guess cannot outrank a literal match. */
    private const CORRECTION_WEIGHT = 0.5;

    /** BM25 term-frequency saturation and length normalisation, at the standard values. */
    private const BM25_K1 = 1.2;
    private const BM25_B = 0.75;

    /** Field weights: a title hit is worth five body hits. */
    private const TITLE_WEIGHT = 1.0;
    private const BODY_WEIGHT = 0.2;

    private const COVERAGE_WEIGHT = 0.5;

    /** Rows per keyset batch in the vector scan. Bounds per-request memory. */
    private const SCAN_BATCH_SIZE = 500;

    /**
     * Meter thresholds on the p95 of the scan phase. The scan is exact and therefore linear
     * in corpus size, so these predict slowdown rather than instability, and the right values
     * follow from that arithmetic rather than from anything a site would tune.
     */
    public const SCAN_WARN_MS = 250;

    public const SCAN_CRIT_MS = 1000;

    private const SCAN_SAMPLE_KEY = 'smart_search_local_scan_ms';

    private const SCAN_SAMPLE_SIZE = 100;

    /**
     * Returns the shape the API controller, the result formatter and the Twig variable
     * all consume.
     *
     * No site means every site Craft lists for this request, so a disabled site is left
     * out of a visitor's search the way Craft leaves it out of their pages.
     *
     * @param list<int>|null $sectionIds null means every section, [] means none
     */
    public function search(string $query, int $limit, ?int $siteId, ?array $sectionIds): array
    {
        if ($sectionIds === []) {
            return [];
        }

        $settings = SmartSearch::getInstance()->getSettings();
        $source = SmartSearch::getInstance()->vectorSource();
        $model = $source->handle();
        $sites = $siteId !== null ? [$siteId] : Craft::$app->getSites()->getAllSiteIds();

        $cacheKey = self::RANKING_CACHE_KEY_PREFIX . md5(implode('|', [
            $query,
            (string)$limit,
            implode(',', $sites),
            $model,
            (string)$settings->dimensions,
            $sectionIds === null ? '' : implode(',', $sectionIds),
            Ranker::settingsFingerprint($settings),
            LocalIndexService::instance()->cacheToken(),
        ]));

        $scoredResults = Ranker::cachedRanking(
            $cacheKey,
            $model,
            fn() => $this->rankResults($query, $limit, $siteId, $sites, $sectionIds, $settings, $source, $model)
        );

        $finalResults = TimingProfiler::profile(
            'Load elements',
            fn() => Ranker::loadElements($scoredResults, $limit, $siteId)
        );

        $finalResults = TimingProfiler::profile(
            'Attach chunk content',
            fn() => Ranker::attachChunkContent($finalResults, $this->chunkBodies($finalResults))
        );

        Logger::debug('Search final results', [
            'requestedLimit' => $limit,
            'returnedResults' => count($finalResults),
        ]);

        return $finalResults;
    }

    /**
     * Both signals, fusion, boosts and the sort.
     *
     * There is no async database handle here, so the only wait available as cover is the
     * vector call. It is dispatched first and everything that needs only the query
     * text (tokenising, correction, BM25, boost matching) runs while it is in flight.
     * The local source resolves immediately and simply has nothing to cover.
     *
     * A site-scoped source gives every site its own vector space, so a search across all
     * sites embeds the query once per site and scans each site with its own vector. The
     * keyword index stores stems in each site's language, so the query is stemmed,
     * corrected and scored once per language, against that language's sites.
     *
     * @param list<int> $sites The sites to search, already resolved from $siteId
     * @param int[]|null $sectionIds
     * @return array<int, array<string, mixed>> Sorted best-first
     */
    private function rankResults(
        string $query,
        int $limit,
        ?int $siteId,
        array $sites,
        ?array $sectionIds,
        Settings $settings,
        VectorSource $source,
        string $model,
    ): array {
        $spaces = $siteId === null && $source->siteScoped()
            ? array_map(static fn(int $site): array => [$site, [$site]], $sites)
            : [[$siteId, $sites]];

        $embeddingPrefetches = TimingProfiler::profile(
            'Embedding dispatch',
            fn() => array_map(fn(array $space): array => [$space[1], $source->dispatch($query, $space[0])], $spaces)
        );

        $sitesByLanguage = [];
        foreach ($sites as $site) {
            $sitesByLanguage[Stemmer::resolveLanguage($site)][] = $site;
        }

        $keywordResults = [];
        $boosts = [];

        foreach ($sitesByLanguage as $language => $languageSites) {
            $lexemes = array_column(Stemmer::terms($query, $language), 1);

            $variants = TimingProfiler::profile(
                'Corrector lookup',
                fn() => $this->correct($lexemes, $languageSites, $language)
            );

            array_push($keywordResults, ...TimingProfiler::profile(
                'Keyword scoring',
                fn() => $this->keywordScores($lexemes, $variants, $languageSites, $sectionIds, $settings)
            ));

            $languageBoosts = TimingProfiler::profile(
                'Boost match',
                fn() => $this->boostMatch($lexemes, $variants, $languageSites)
            );
            foreach ($languageBoosts as $elementId => $boost) {
                if ($boost['weight'] > ($boosts[$elementId]['weight'] ?? 0.0)) {
                    $boosts[$elementId] = $boost;
                }
            }
        }

        if (count($sitesByLanguage) > 1) {
            $keywordResults = self::bestPerEntry($keywordResults, 'keywordScore', $settings->maxSemanticResults);
        }

        $semanticLimit = min($settings->maxSemanticResults, $limit * 10);
        $semanticResults = [];

        foreach ($embeddingPrefetches as [$scanSites, $prefetch]) {
            $queryVector = TimingProfiler::profile('Query embedding wait', $prefetch);
            array_push($semanticResults, ...$this->vectorScan($queryVector, $model, $semanticLimit, $scanSites, $sectionIds));
        }

        if (count($embeddingPrefetches) > 1) {
            $semanticResults = self::bestPerEntry($semanticResults, 'similarity', $semanticLimit);
        }

        return Ranker::rank($semanticResults, $keywordResults, $boosts, $settings);
    }

    /**
     * Exact cosine similarity over every stored vector for the given sites.
     *
     * Vectors are stored unit-normalised, so cosine is a plain dot product. Rows are
     * read by keyset pagination and unpacked one at a time, and only the running top-k
     * is retained, so peak memory is one batch plus k regardless of corpus size.
     *
     * Over-fetches chunks and collapses to each entry's best chunk.
     *
     * ponytail: pure-PHP dot product, no SIMD. Cost is linear in chunk count (roughly
     * 20us per chunk at 512 dimensions), which the dashboard meter reports as a p95.
     * Upgrade path when that p95 stops being acceptable, cheapest first: int8
     * quantisation of the stored vector, then a k-means clusterId column scanning only
     * the nearest centroids, then a native vector index. None of them are here because
     * the meter has not yet said which one a real corpus needs.
     *
     * @param int[]|null $sectionIds
     * @return array<array{elementId: int, siteId: int, chunkIndex: int, similarity: float}>
     */
    private function vectorScan(array $queryVector, string $model, int $limit, array $sites, ?array $sectionIds): array
    {
        $q = LocalIndexService::normalise($queryVector);
        $dimensions = count($q);
        if ($dimensions === 0) {
            return [];
        }

        $q = array_combine(range(1, $dimensions), array_values($q));

        $keep = max(Ranker::MIN_OVERFETCH, $limit * Ranker::OVERFETCH_MULTIPLIER);
        $batchSize = self::SCAN_BATCH_SIZE;

        $heap = new SplMinHeap();
        $scanned = 0;
        $started = microtime(true);

        $query = (new Query())
            ->select(['id', 'elementId', 'siteId', 'chunkIndex', 'vector'])
            ->from(LocalSchema::VECTORS_TABLE)
            ->where(['dims' => $dimensions, 'model' => $model, 'siteId' => $sites])
            ->andFilterWhere(['sectionId' => $sectionIds]);

        foreach (LocalSchema::batches($query, $batchSize) as $rows) {
            foreach ($rows as $row) {
                $v = LocalIndexService::unpack($row['vector']);

                $similarity = 0.0;
                for ($i = 1; $i <= $dimensions; $i++) {
                    $similarity += $q[$i] * $v[$i];
                }

                if ($heap->count() >= $keep) {
                    if ($similarity <= $heap->top()[0]) {
                        continue;
                    }
                    $heap->extract();
                }

                $heap->insert([
                    $similarity,
                    (int)$row['elementId'],
                    (int)$row['siteId'],
                    (int)$row['chunkIndex'],
                ]);
            }

            $scanned += count($rows);
        }

        $elapsedMs = round((microtime(true) - $started) * 1000, 2);
        self::recordScanMs($elapsedMs, $scanned);
        TimingProfiler::record('Local vector scan', $elapsedMs, ['chunks' => $scanned, 'dims' => $dimensions]);

        $candidates = [];
        foreach ($heap as $entry) {
            $candidates[] = $entry;
        }
        $candidates = array_reverse($candidates);

        $best = [];
        foreach ($candidates as [$similarity, $elementId, $rowSiteId, $chunkIndex]) {
            if (isset($best[$elementId])) {
                continue;
            }
            $best[$elementId] = [
                'elementId' => $elementId,
                'siteId' => $rowSiteId,
                'chunkIndex' => $chunkIndex,
                'similarity' => $similarity,
            ];
        }

        return array_slice(array_values($best), 0, $limit);
    }

    /**
     * Merge per-site or per-language result lists the way one list collapses its rows:
     * best first by $score, one hit per entry.
     *
     * @param list<array<string, mixed>> $results Each row has elementId and $score
     * @return list<array<string, mixed>>
     */
    private static function bestPerEntry(array $results, string $score, int $limit): array
    {
        usort($results, static fn(array $a, array $b): int => $b[$score] <=> $a[$score]);

        $best = [];
        foreach ($results as $result) {
            $best[$result['elementId']] ??= $result;
        }

        return array_slice(array_values($best), 0, $limit);
    }

    /**
     * BM25 over the inverted index, collapsed to each entry's best-scoring chunk.
     *
     * Scores are squashed to 0..1 as `s / (s + 1)`, the scale Ranker::fuse()'s absolute
     * strong-hit threshold is set against; raw BM25 has no upper bound.
     *
     * `coverage` is the share of the query's words the entry contains anywhere, across all
     * its chunks. A typo correction counts for the word it corrects.
     *
     * @param string[] $lexemes Query lexemes, in order
     * @param array<string, string> $variants lexeme => typo-corrected lexeme
     * @param int[]|null $sectionIds
     * @return list<array{elementId: int, siteId: int, keywordScore: float, coverage: float, chunk: array{0: int, 1: int, 2: int}}>
     */
    private function keywordScores(array $lexemes, array $variants, array $sites, ?array $sectionIds, Settings $settings): array
    {
        $original = array_flip(array_unique($lexemes));
        $terms = array_map('strval', array_keys($original + array_flip(array_values($variants))));
        if ($terms === []) {
            return [];
        }

        [$totalChunks, $avgLength] = $this->corpusStats($sites);
        if ($totalChunks === 0) {
            return [];
        }

        $documentFrequency = (new Query())
            ->select(['term', 'df'])
            ->from(LocalSchema::TERMS_TABLE)
            ->where(['term' => $terms, 'siteId' => $sites])
            ->all();

        $scorable = [];
        foreach ($documentFrequency as $row) {
            $df = (int)$row['df'];
            if ($df > 0) {
                $scorable[(string)$row['term']] = $df;
            }
        }

        if ($scorable === []) {
            return [];
        }

        $originalParams = [];
        $isOriginal = Craft::$app->getDb()->getQueryBuilder()->buildCondition(
            ['in', 'p.term', array_map('strval', array_keys($original))],
            $originalParams,
        );
        $isOriginal = new Expression("CASE WHEN {$isOriginal} THEN 1 ELSE 0 END", $originalParams);

        $query = (new Query())
            ->select([
                'p.elementId',
                'p.siteId',
                'p.chunkIndex',
                'p.term',
                'p.field',
                'p.tf',
                'c.tokenCount',
                'df' => 't.df',
                'isOriginal' => $isOriginal,
            ])
            ->from(['p' => LocalSchema::POSTINGS_TABLE])
            ->innerJoin(['c' => LocalSchema::CHUNKS_TABLE], implode(' AND ', [
                '[[c.elementId]] = [[p.elementId]]',
                '[[c.siteId]] = [[p.siteId]]',
                '[[c.chunkIndex]] = [[p.chunkIndex]]',
            ]))
            ->innerJoin(['t' => LocalSchema::TERMS_TABLE], implode(' AND ', [
                '[[t.siteId]] = [[p.siteId]]',
                '[[t.term]] = [[p.term]]',
            ]))
            ->where(['p.term' => array_map('strval', array_keys($scorable))])
            ->andWhere(['p.siteId' => $sites])
            ->andFilterWhere(['c.sectionId' => $sectionIds]);

        $k1 = self::BM25_K1;
        $b = self::BM25_B;
        $fieldWeights = ['title' => self::TITLE_WEIGHT, 'body' => self::BODY_WEIGHT];

        $chunkScores = [];
        $entryTerms = [];
        foreach ($query->all() as $row) {
            $key = $row['elementId'] . '-' . $row['siteId'] . '-' . $row['chunkIndex'];
            $tf = (int)$row['tf'];
            $length = max(1, (int)$row['tokenCount']);

            $idf = LocalContextVectors::idf($totalChunks, (int)$row['df']);
            $tfNorm = ($tf * ($k1 + 1)) / ($tf + $k1 * (1 - $b + $b * ($length / $avgLength)));
            $weight = $fieldWeights[$row['field']] ?? 0.0;

            if ((int)$row['isOriginal'] === 0) {
                $weight *= self::CORRECTION_WEIGHT;
            }

            $chunkScores[$key] ??= [
                'elementId' => (int)$row['elementId'],
                'siteId' => (int)$row['siteId'],
                'chunkIndex' => (int)$row['chunkIndex'],
                'score' => 0.0,
                'matched' => [],
            ];
            $chunkScores[$key]['score'] += $idf * $tfNorm * $weight;
            $chunkScores[$key]['matched'][(string)$row['term']] = true;
            $entryTerms[(int)$row['elementId']][(string)$row['term']] = true;
        }

        $queryTermCount = max(1, count($original));
        foreach ($chunkScores as $key => $chunk) {
            $coverage = min(1.0, count($chunk['matched']) / $queryTermCount);
            $chunkScores[$key]['score'] = $chunk['score'] * ($coverage ** self::COVERAGE_WEIGHT);
        }

        $best = [];
        foreach ($chunkScores as $chunk) {
            $current = $best[$chunk['elementId']] ?? null;
            if ($current === null || $chunk['score'] > $current['score']) {
                $best[$chunk['elementId']] = $chunk;
            }
        }

        usort($best, fn(array $a, array $b) => $b['score'] <=> $a['score']);

        return array_map(
            static fn(array $chunk): array => [
                'elementId' => $chunk['elementId'],
                'siteId' => $chunk['siteId'],
                'keywordScore' => $chunk['score'] / ($chunk['score'] + 1),
                'coverage' => min(1.0, count($entryTerms[$chunk['elementId']]) / $queryTermCount),
                'chunk' => [$chunk['elementId'], $chunk['siteId'], $chunk['chunkIndex']],
            ],
            array_slice($best, 0, $settings->maxSemanticResults),
        );
    }

    /**
     * Typo correction against the local dictionary.
     *
     * The band is an indexed column and levenshtein() is native, so this needs no database
     * extension. Choosing between the candidates is TypoCorrector's.
     *
     * Deliberately no leading-character restriction, so a first-letter typo still corrects.
     *
     * @param string[] $lexemes
     * @return array<string, string> lexeme => corrected lexeme, for corrected lexemes only
     */
    private function correct(array $lexemes, array $sites, string $language): array
    {
        if ($lexemes === []) {
            return [];
        }

        $cache = Craft::$app->getCache();
        $token = LocalIndexService::instance()->cacheToken();
        $cachePrefix = self::VARIANT_CACHE_KEY_PREFIX . md5($token . '|' . $language . '|' . implode(',', $sites)) . '_';

        $variants = [];
        $unknown = [];
        foreach (array_unique($lexemes) as $lexeme) {
            if (!TypoCorrector::isCorrectable($lexeme)) {
                continue;
            }
            $cached = $cache->get($cachePrefix . $lexeme);
            if (is_string($cached)) {
                if ($cached !== '') {
                    $variants[$lexeme] = $cached;
                }
                continue;
            }
            $unknown[] = $lexeme;
        }

        if ($unknown === []) {
            return $variants;
        }

        $known = (new Query())
            ->select(['term'])
            ->from(LocalSchema::TERMS_TABLE)
            ->where(['term' => $unknown, 'siteId' => $sites])
            ->column();

        $misspelled = array_values(array_diff($unknown, $known));
        foreach ($known as $term) {
            $cache->set($cachePrefix . $term, '', self::VARIANT_CACHE_TTL_SECONDS, CacheTag::dependency());
        }

        if ($misspelled === []) {
            return $variants;
        }

        $bands = ['or'];
        foreach ($misspelled as $lexeme) {
            $length = strlen($lexeme);
            $ceiling = TypoCorrector::ceiling($lexeme);
            $bands[] = ['between', 'termLength', $length - $ceiling, $length + $ceiling];
        }

        $candidates = (new Query())
            ->select(['term', 'df'])
            ->from(LocalSchema::TERMS_TABLE)
            ->where($bands)
            ->andWhere(['siteId' => $sites])
            ->limit(TypoCorrector::CANDIDATE_LIMIT)
            ->all();

        foreach ($misspelled as $lexeme) {
            $bestTerm = TypoCorrector::best($lexeme, $candidates);

            $cache->set($cachePrefix . $lexeme, $bestTerm ?? '', self::VARIANT_CACHE_TTL_SECONDS, CacheTag::dependency());
            if ($bestTerm !== null) {
                $variants[$lexeme] = $bestTerm;
            }
        }

        return $variants;
    }

    /**
     * Sum the weight of every boost rule whose phrases all appear in the query, with
     * their words adjacent. Summed per site, then the best site counts, so an entry with
     * the same rules on several sites is boosted as much as on one of them, not more.
     *
     * A rule "1 bedroom" must not fire for the query "2 bedroom 1 bathroom", even though
     * both of its words appear. Typo variants sit at the same position as
     * the word they correct, so a misspelling keeps the phrase intact without letting a
     * wrong guess lose the literal match.
     *
     * ponytail: siteId-filtered scan over the rules. A lexeme pre-filter column is the
     * upgrade if a site ever has tens of thousands of rules.
     *
     * @param string[] $lexemes
     * @param array<string, string> $variants
     * @return array<int, array{weight: float, siteId: int}> elementId => summed weight on its best site, and that site
     */
    private function boostMatch(array $lexemes, array $variants, array $sites): array
    {
        if ($lexemes === []) {
            return [];
        }

        $rules = (new Query())
            ->select(['elementId', 'siteId', 'terms', 'weight'])
            ->from(LocalSchema::BOOSTS_TABLE)
            ->where(['siteId' => $sites])
            ->all();

        if ($rules === []) {
            return [];
        }

        $positions = [];
        foreach ($lexemes as $index => $lexeme) {
            $accepted = [$lexeme => true];
            if (isset($variants[$lexeme])) {
                $accepted[$variants[$lexeme]] = true;
            }
            $positions[$index + 1] = $accepted;
        }

        $boosts = [];
        foreach ($rules as $rule) {
            foreach (json_decode($rule['terms'], true) as $phrase) {
                if (!self::phraseMatches($phrase, $positions)) {
                    continue 2;
                }
            }

            $elementId = (int)$rule['elementId'];
            $siteId = (int)$rule['siteId'];
            $boosts[$elementId][$siteId] = ($boosts[$elementId][$siteId] ?? 0.0) + (float)$rule['weight'];
        }

        return array_map(static function(array $bySite): array {
            arsort($bySite);
            return ['weight' => reset($bySite), 'siteId' => key($bySite)];
        }, $boosts);
    }

    /**
     * True when the phrase's lexemes occupy consecutive query positions.
     *
     * @param string[] $phrase
     * @param array<int, array<string, true>> $positions
     */
    private static function phraseMatches(array $phrase, array $positions): bool
    {
        $phrase = array_values($phrase);

        foreach (array_keys($positions) as $start) {
            foreach ($phrase as $offset => $lexeme) {
                if (!isset($positions[$start + $offset][$lexeme])) {
                    continue 2;
                }
            }
            return true;
        }

        return false;
    }

    /**
     * The excerpt text for the results that survived, by chunk key, in one query.
     *
     * Ranking never reads the chunk text, so it is fetched only for the handful of rows
     * that will actually be shown: the same reason the scan returns chunk keys
     * from its vector query rather than bodies.
     *
     * @return array<string, string> "elementId-siteId-chunkIndex" => body
     */
    private function chunkBodies(array $finalResults): array
    {
        $keys = [];
        foreach ($finalResults as $result) {
            if ($result['chunk'] !== null) {
                [$elementId, $rowSiteId, $chunkIndex] = $result['chunk'];
                $keys[] = [
                    'elementId' => (int)$elementId,
                    'siteId' => (int)$rowSiteId,
                    'chunkIndex' => (int)$chunkIndex,
                ];
            }
        }

        if ($keys === []) {
            return [];
        }

        $rows = (new Query())
            ->select(['elementId', 'siteId', 'chunkIndex', 'body'])
            ->from(LocalSchema::CHUNKS_TABLE)
            ->where(['in', ['elementId', 'siteId', 'chunkIndex'], $keys])
            ->all();

        $bodies = [];
        foreach ($rows as $row) {
            $bodies["{$row['elementId']}-{$row['siteId']}-{$row['chunkIndex']}"] = (string)$row['body'];
        }

        return $bodies;
    }

    /**
     * Chunk count and mean chunk length for the sites, which BM25 needs as N and avgdl.
     * Cached against the local store's token, so a write refreshes it.
     *
     * @return array{0: int, 1: float}
     */
    private function corpusStats(array $sites): array
    {
        $cache = Craft::$app->getCache();
        $key = 'smart_search_local_corpus_' . md5(
            LocalIndexService::instance()->cacheToken() . '|' . implode(',', $sites)
        );

        $stats = $cache->get($key);
        if (!is_array($stats)) {
            $row = (new Query())
                ->select(['total' => 'COUNT(*)', 'meanLength' => 'AVG([[tokenCount]])'])
                ->from(LocalSchema::CHUNKS_TABLE)
                ->where(['siteId' => $sites])
                ->one();

            $stats = [(int)($row['total'] ?? 0), max(1.0, (float)($row['meanLength'] ?? 1))];
            $cache->set($key, $stats, Ranker::RANKING_CACHE_TTL_SECONDS, CacheTag::dependency());
        }

        return $stats;
    }

    /**
     * Keep the last N scans as {ms, chunks} pairs, so the dashboard can report a p95 and
     * a per-chunk rate without a table.
     *
     * The chunk count is stored with the duration rather than divided out of the current
     * total later: a sample taken when the corpus was a different size would otherwise
     * yield a per-chunk rate that is wrong by whatever the corpus has changed by, and the
     * headroom projection reads as nonsense.
     */
    private static function recordScanMs(float $ms, int $chunks): void
    {
        $cache = Craft::$app->getCache();
        $samples = $cache->get(self::SCAN_SAMPLE_KEY);
        $samples = is_array($samples) ? $samples : [];

        $samples[] = ['ms' => $ms, 'chunks' => $chunks];
        if (count($samples) > self::SCAN_SAMPLE_SIZE) {
            $samples = array_slice($samples, -self::SCAN_SAMPLE_SIZE);
        }

        $cache->set(self::SCAN_SAMPLE_KEY, $samples, 0, CacheTag::dependency());
    }

    /**
     * The recorded scans, oldest first.
     *
     * @return array<array{ms: float, chunks: int}>
     */
    public static function scanSamples(): array
    {
        $samples = Craft::$app->getCache()->get(self::SCAN_SAMPLE_KEY);

        return is_array($samples) ? $samples : [];
    }

    /** Nearest-rank p95 of the recorded scan durations, or null with no samples. */
    public static function scanP95(): ?float
    {
        return self::nearestRank(array_column(self::scanSamples(), 'ms'), 95);
    }

    /**
     * Median milliseconds per chunk, measured. The scan is exact and therefore linear in
     * chunk count, so this is what makes the dashboard's headroom projection meaningful.
     */
    public static function scanMsPerChunk(): ?float
    {
        $rates = [];
        foreach (self::scanSamples() as $sample) {
            if ($sample['chunks'] > 0) {
                $rates[] = $sample['ms'] / $sample['chunks'];
            }
        }

        return self::nearestRank($rates, 50);
    }

    /**
     * @param float[] $values
     */
    private static function nearestRank(array $values, int $percentile): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $index = (int)ceil(($percentile / 100) * count($values)) - 1;

        return $values[max(0, min($index, count($values) - 1))];
    }
}

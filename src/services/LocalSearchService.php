<?php

namespace ghoststreet\craftsmartsearch\services;

use Craft;
use craft\db\Query;
use ghoststreet\craftsmartsearch\helpers\Logger;
use ghoststreet\craftsmartsearch\helpers\Ranker;
use ghoststreet\craftsmartsearch\helpers\Stemmer;
use ghoststreet\craftsmartsearch\helpers\TimingProfiler;
use ghoststreet\craftsmartsearch\helpers\UsageTracker;
use ghoststreet\craftsmartsearch\migrations\m260905_000000_local_index as LocalTables;
use ghoststreet\craftsmartsearch\models\Settings;
use ghoststreet\craftsmartsearch\SmartSearch;
use SplMinHeap;
use yii\base\Component;
use yii\db\Expression;

/**
 * The `local` search type: the same hybrid ranking as SmartSearchService, scored against
 * Craft's own database instead of pgvector Postgres.
 *
 * Semantic similarity is an exact cosine scan in PHP over every stored vector for the
 * site, read in keyset batches so memory stays flat at any index size. There is no
 * approximate index and no shortlist mode, so recall is identical to an exhaustive
 * pgvector scan; the cost is linear in corpus size, which is what the dashboard meter
 * reports. Keyword scoring is BM25 over an inverted index, typo correction is PHP's
 * levenshtein() over a length-banded dictionary lookup, and boost rules are matched by
 * phrase adjacency over the query's lexeme positions.
 *
 * Fusion, the boost merge and element hydration are Ranker's, shared with every other
 * type, so a quality difference between types comes from this file rather than from
 * two copies of RRF drifting apart.
 */
class LocalSearchService extends Component
{
    private const RANKING_CACHE_TTL_SECONDS = 60;

    private const RANKING_CACHE_KEY_PREFIX = 'smart_search_local_ranking_';

    /** Below this length a typo correction is more likely to be wrong than right. */
    private const MIN_CORRECTION_LENGTH = 4;

    /** Ceiling on the corrector's length-band candidate fetch. */
    private const CORRECTION_CANDIDATE_LIMIT = 20000;

    private const VARIANT_CACHE_TTL_SECONDS = 2592000;

    private const VARIANT_CACHE_KEY_PREFIX = 'smart_search_local_variant_';

    /** A correction scores at half weight, matching the pgvector keyword query. */
    private const CORRECTION_WEIGHT = 0.5;

    private const SCAN_SAMPLE_KEY = 'smart_search_local_scan_ms';

    private const SCAN_SAMPLE_SIZE = 100;

    /**
     * Same signature and same return shape as SmartSearchService::search(), so the API
     * controller, the result formatter and the Twig variable all work unchanged.
     *
     * @param string[]|null $sections Restrict results to these section handles
     */
    public function search(string $query, int $limit = 10, ?int $siteId = null, ?string $embeddingModel = null, ?array $sections = null): array
    {
        $settings = SmartSearch::getInstance()->getSettings();

        $sectionIds = null;
        if (!empty($sections)) {
            $sectionIds = Ranker::resolveSectionIds($sections);
            if ($sectionIds === []) {
                Logger::debug('Section filter matched no known sections', ['sections' => $sections]);
                return [];
            }
        }

        $model = $embeddingModel ?? $settings->localEmbeddingModel;

        $cacheKey = self::RANKING_CACHE_KEY_PREFIX . md5(implode('|', [
            $query,
            (string)$limit,
            (string)($siteId ?? ''),
            $model,
            (string)$settings->localDimensions,
            $sectionIds === null ? '' : implode(',', $sectionIds),
            Ranker::settingsFingerprint($settings, implode(',', [
                'local',
                $settings->localBm25K1,
                $settings->localBm25B,
                $settings->localTitleWeight,
                $settings->localBodyWeight,
                $settings->localCoverageWeight,
            ])),
            SmartSearch::getInstance()->localIndexService->cacheToken(),
        ]));

        /* Only the ranking is cached, never the payload, and the local store's token is
           in the key so a write invalidates every cached ranking at once. */
        $cache = Craft::$app->getCache();
        $scoredResults = $cache->get($cacheKey);

        if (is_array($scoredResults)) {
            UsageTracker::markEmbeddingCached($model);
        } else {
            $scoredResults = TimingProfiler::profile(
                'Ranking',
                fn() => $this->rankResults($query, $limit, $siteId, $model, $sectionIds, $settings)
            );
            $cache->set($cacheKey, $scoredResults, self::RANKING_CACHE_TTL_SECONDS);
        }

        $finalResults = TimingProfiler::profile(
            'Load elements',
            fn() => Ranker::loadElements($scoredResults, $limit, $siteId)
        );

        $finalResults = TimingProfiler::profile(
            'Attach chunk content',
            fn() => Ranker::attachChunkContent($finalResults, $this->chunkContentFetcher($finalResults))
        );

        Logger::debug('Local search final results', [
            'requestedLimit' => $limit,
            'returnedResults' => count($finalResults),
        ]);

        return $finalResults;
    }

    /**
     * Both signals, fusion, boosts and the sort.
     *
     * There is no async database handle here, so the only wait available as cover is the
     * embedding call. It is dispatched first and everything that needs only the query
     * text — tokenising, correction, BM25, boost matching — runs while it is in flight.
     *
     * @param int[]|null $sectionIds
     * @return array<int, array<string, mixed>> Sorted best-first
     */
    private function rankResults(
        string $query,
        int $limit,
        ?int $siteId,
        string $model,
        ?array $sectionIds,
        Settings $settings,
    ): array {
        $embeddingPrefetch = TimingProfiler::profile(
            'Embedding dispatch',
            fn() => SmartSearch::getInstance()->embeddingService->dispatchEmbedding(
                $query,
                $model,
                $settings->localDimensions,
            )
        );

        $language = KeywordSearchService::resolveLanguage($siteId);
        /* Stopwords are absent from the index, so carrying them into the query would only
           cost lookups. Postgres drops them from the tsquery for the same reason. */
        $lexemes = array_column(array_filter(
            Stemmer::tokenizeAndStem($query, $language),
            static fn(array $pair): bool => !Stemmer::isStopword($pair[0], $language),
        ), 1);

        $variants = TimingProfiler::profile(
            'Local corrector lookup',
            fn() => $this->correct($lexemes, $siteId, $language, $settings)
        );

        $keywordResults = TimingProfiler::profile(
            'Local keyword scoring',
            fn() => $this->keywordScores($lexemes, $variants, $siteId, $sectionIds, $settings)
        );

        $boosts = TimingProfiler::profile(
            'Local boost match',
            fn() => $this->boostMatch($lexemes, $variants, $siteId)
        );

        $queryVector = TimingProfiler::profile('Query embedding wait', $embeddingPrefetch);

        $semanticResults = $this->vectorScan(
            $queryVector,
            min($settings->maxSemanticResults, $limit * 10),
            $siteId,
            $sectionIds,
            $settings,
        );

        $semanticLookup = Ranker::semanticLookup($semanticResults);
        $keywordLookup = Ranker::keywordLookup($keywordResults);

        $scoredResults = Ranker::fuse($semanticLookup, $keywordLookup, $settings);

        Logger::debug('Local search RRF', [
            'semanticRawRows' => count($semanticResults),
            'semanticUniqueElements' => count($semanticLookup),
            'keywordUniqueElements' => count($keywordLookup),
            'survived' => count($scoredResults),
        ]);

        Ranker::applyBoosts($scoredResults, $boosts);

        uasort($scoredResults, fn($a, $b) => $b['rrfScore'] <=> $a['rrfScore']);

        return $scoredResults;
    }

    /**
     * Exact cosine similarity over every stored vector for the site.
     *
     * Vectors are stored unit-normalised, so cosine is a plain dot product. Rows are
     * read by keyset pagination and unpacked one at a time, and only the running top-k
     * is retained, so peak memory is one batch plus k regardless of corpus size.
     *
     * Over-fetches chunks and collapses to each entry's best chunk, using the same
     * windows as the pgvector path so the two return comparable candidate sets.
     *
     * ponytail: pure-PHP dot product, no SIMD. Cost is linear in chunk count — roughly
     * 20us per chunk at 512 dimensions — which the dashboard meter reports as a p95.
     * Upgrade path when that p95 stops being acceptable, cheapest first: int8
     * quantisation of the stored vector, then a k-means clusterId column scanning only
     * the nearest centroids, then the pgvector driver. None of them are here because
     * the meter has not yet said which one a real corpus needs.
     *
     * @param int[]|null $sectionIds
     * @return array<array{elementId: int, siteId: int, chunkIndex: int, similarity: float}>
     */
    private function vectorScan(array $queryVector, int $limit, ?int $siteId, ?array $sectionIds, Settings $settings): array
    {
        $dimensions = count($queryVector);
        if ($dimensions === 0) {
            return [];
        }

        /* 1-indexed to match unpack()'s output, so the inner loop needs no offset maths. */
        $q = array_combine(range(1, $dimensions), array_values(self::normalise($queryVector)));

        $keep = max(Ranker::MIN_OVERFETCH, $limit * Ranker::OVERFETCH_MULTIPLIER);
        $batchSize = max(50, $settings->localScanBatchSize);
        $format = LocalIndexService::PACK_FORMAT . '*';

        $heap = new SplMinHeap();
        $lastId = 0;
        $scanned = 0;
        $started = microtime(true);

        while (true) {
            $rows = (new Query())
                ->select(['id', 'elementId', 'siteId', 'chunkIndex', 'vector'])
                ->from(LocalTables::VECTORS_TABLE)
                /* Filtered in SQL rather than skipped in PHP: a stored width other than
                   the current setting is stale, and scoring it would be meaningless. */
                ->where(['dims' => $dimensions])
                ->andWhere(['>', 'id', $lastId])
                ->andFilterWhere(['siteId' => $siteId])
                ->andFilterWhere(['sectionId' => $sectionIds])
                ->orderBy(['id' => SORT_ASC])
                ->limit($batchSize)
                ->all();

            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $v = unpack($format, $row['vector']);
                if ($v === false || count($v) !== $dimensions) {
                    continue;
                }

                $similarity = 0.0;
                for ($i = 1; $i <= $dimensions; $i++) {
                    $similarity += $q[$i] * $v[$i];
                }

                /* The heap's first element is the similarity, so its natural array
                   ordering is the ordering we want, with ids as a stable tie-break. */
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
            $lastId = (int)$rows[count($rows) - 1]['id'];
        }

        $elapsedMs = round((microtime(true) - $started) * 1000, 2);
        self::recordScanMs($elapsedMs, $scanned);
        TimingProfiler::record('Local vector scan', $elapsedMs, ['chunks' => $scanned, 'dims' => $dimensions]);

        $candidates = [];
        foreach ($heap as $entry) {
            $candidates[] = $entry;
        }
        /* The heap drains smallest-first. */
        $candidates = array_reverse($candidates);

        /* Collapse to each entry's best-scoring chunk, keeping rank order. */
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
     * BM25 over the inverted index, collapsed to each entry's best-scoring chunk.
     *
     * Scores are squashed to 0..1 as `s / (s + 1)`, which is what ts_rank_cd's
     * normalisation flag 32 does on the pgvector side. Without it Ranker::fuse()'s
     * absolute `keywordScore >= 0.5` strong-hit bonus would mean something different
     * for this type than for that one.
     *
     * @param string[] $lexemes Query lexemes, in order
     * @param array<string, string> $variants lexeme => typo-corrected lexeme
     * @param int[]|null $sectionIds
     * @return list<array{elementId: int, siteId: int, keywordScore: float, content: string, chunk: array{0: int, 1: int, 2: int}}>
     */
    private function keywordScores(array $lexemes, array $variants, ?int $siteId, ?array $sectionIds, Settings $settings): array
    {
        $original = array_flip(array_unique($lexemes));
        /* strval because a numeric lexeme becomes an int array key, and a mixed IN list
           makes MySQL compare the varchar term column numerically. */
        $terms = array_map('strval', array_keys($original + array_flip(array_values($variants))));
        if ($terms === []) {
            return [];
        }

        [$totalChunks, $avgLength] = $this->corpusStats($siteId);
        if ($totalChunks === 0) {
            return [];
        }

        $documentFrequency = (new Query())
            ->select(['term', 'df'])
            ->from(LocalTables::TERMS_TABLE)
            ->where(['term' => $terms])
            ->andFilterWhere(['siteId' => $siteId])
            ->all();

        /*
         * Every term the corpus actually contains is scored, however common it is.
         *
         * A frequency cutoff was tried here and was badly wrong: on a site about
         * architecture the stem "architectur" appears in 63% of chunks, so a 30% cutoff
         * threw away the one word the query was about and returned nothing. Postgres does
         * not do this either — ts_rank_cd keeps every lexeme and lets the weighting decide.
         * BM25's idf already collapses a ubiquitous term to near zero (df 1174 of 1285
         * scores 0.09 against a rare term's 2.5), which is the same answer without a
         * constant to get wrong.
         */
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

        /*
         * df and the original/corrected split are both resolved in SQL rather than by
         * looking the row's term up in a PHP array.
         *
         * The database compares strings by its own collation, which on MySQL is accent
         * and case insensitive: "kahui" and "kāhui" are one value to it. So the dictionary
         * holds a single row for them, while the postings hold both spellings, and an IN
         * list matches a row whose term is not a key in any PHP map built from the other
         * table. Joining and comparing in SQL keeps one notion of equality throughout.
         */
        $originalParams = [];
        foreach (array_keys($original) as $i => $term) {
            $originalParams[":ot{$i}"] = (string)$term;
        }
        $isOriginal = $originalParams === []
            ? new Expression('0')
            : new Expression(
                'CASE WHEN [[p.term]] IN (' . implode(', ', array_keys($originalParams)) . ') THEN 1 ELSE 0 END',
                $originalParams,
            );

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
            ->from(['p' => LocalTables::POSTINGS_TABLE])
            ->innerJoin(['c' => LocalTables::CHUNKS_TABLE], implode(' AND ', [
                '[[c.elementId]] = [[p.elementId]]',
                '[[c.siteId]] = [[p.siteId]]',
                '[[c.chunkIndex]] = [[p.chunkIndex]]',
            ]))
            ->innerJoin(['t' => LocalTables::TERMS_TABLE], implode(' AND ', [
                '[[t.siteId]] = [[p.siteId]]',
                '[[t.term]] = [[p.term]]',
            ]))
            ->where(['p.term' => array_map('strval', array_keys($scorable))])
            ->andFilterWhere(['p.siteId' => $siteId])
            ->andFilterWhere(['c.sectionId' => $sectionIds]);

        $k1 = $settings->localBm25K1;
        $b = $settings->localBm25B;
        $fieldWeights = ['title' => $settings->localTitleWeight, 'body' => $settings->localBodyWeight];

        $chunkScores = [];
        foreach ($query->all() as $row) {
            $key = $row['elementId'] . '-' . $row['siteId'] . '-' . $row['chunkIndex'];
            $df = max(1, (int)$row['df']);
            $tf = (int)$row['tf'];
            $length = max(1, (int)$row['tokenCount']);

            $idf = log(($totalChunks - $df + 0.5) / ($df + 0.5) + 1);
            $tfNorm = ($tf * ($k1 + 1)) / ($tf + $k1 * (1 - $b + $b * ($length / $avgLength)));
            $weight = $fieldWeights[$row['field']] ?? 0.0;

            /* A term that only reached the query through a correction contributes at
               half weight, so a wrong guess cannot outrank a literal match. */
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
        }

        /*
         * Cover density. BM25 scores each term on its own, so a chunk carrying one query
         * term ten times can beat one carrying every term once. Scaling by the share of
         * distinct query terms present restores the preference ts_rank_cd already has.
         */
        $coverageWeight = $settings->localCoverageWeight;
        if ($coverageWeight > 0.0) {
            $queryTermCount = max(1, count($original));
            foreach ($chunkScores as $key => $chunk) {
                $coverage = min(1.0, count($chunk['matched']) / $queryTermCount);
                $chunkScores[$key]['score'] = $chunk['score'] * ($coverage ** $coverageWeight);
            }
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
                /* Squashed to 0..1, matching ts_rank_cd's flag 32. */
                'keywordScore' => $chunk['score'] / ($chunk['score'] + 1),
                'content' => '',
                'chunk' => [$chunk['elementId'], $chunk['siteId'], $chunk['chunkIndex']],
            ],
            array_slice($best, 0, $settings->maxSemanticResults),
        );
    }

    /**
     * Typo correction against the local dictionary.
     *
     * Replaces the pgvector path's pg_trgm word_similarity plus levenshtein_less_equal
     * with PHP's own levenshtein() over a length-banded candidate fetch. The band is
     * indexed, levenshtein() is native, and the same edit-distance ceiling applies:
     * 1 below 8 characters, 2 at or above.
     *
     * Deliberately no leading-character restriction, so a first-letter typo still
     * corrects the way a trigram comparison does.
     *
     * ponytail: levenshtein() counts bytes, and termLength is stored in bytes to match,
     * so distances are character-accurate only for single-byte scripts. Good enough for
     * the Latin-script corpora this is being compared on; a multibyte implementation is
     * the upgrade if a non-Latin site needs it.
     *
     * @param string[] $lexemes
     * @return array<string, string> lexeme => corrected lexeme, for corrected lexemes only
     */
    private function correct(array $lexemes, ?int $siteId, string $language, Settings $settings): array
    {
        if (!$settings->enableTypoTolerance || $lexemes === []) {
            return [];
        }

        $cache = Craft::$app->getCache();
        $token = SmartSearch::getInstance()->localIndexService->cacheToken();
        $cachePrefix = self::VARIANT_CACHE_KEY_PREFIX . md5($token . '|' . $language . '|' . ($siteId ?? '')) . '_';

        $variants = [];
        $unknown = [];
        foreach (array_unique($lexemes) as $lexeme) {
            if (strlen($lexeme) < self::MIN_CORRECTION_LENGTH) {
                continue;
            }
            $cached = $cache->get($cachePrefix . $lexeme);
            if (is_string($cached)) {
                /* An empty string is a cached "no correction", so an unknown word does
                   not re-query on every search. */
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

        /* One query for the exact hits: a lexeme the corpus already contains needs no
           correction, and asking is a primary-key lookup. */
        $known = (new Query())
            ->select(['term'])
            ->from(LocalTables::TERMS_TABLE)
            ->where(['term' => $unknown])
            ->andFilterWhere(['siteId' => $siteId])
            ->column();

        $misspelled = array_values(array_diff($unknown, $known));
        foreach ($known as $term) {
            $cache->set($cachePrefix . $term, '', self::VARIANT_CACHE_TTL_SECONDS);
        }

        if ($misspelled === []) {
            return $variants;
        }

        $bands = ['or'];
        $ceilings = [];
        foreach ($misspelled as $lexeme) {
            $length = strlen($lexeme);
            $ceilings[$lexeme] = $length >= 8 ? 2 : 1;
            $bands[] = ['between', 'termLength', $length - $ceilings[$lexeme], $length + $ceilings[$lexeme]];
        }

        $candidates = (new Query())
            ->select(['term', 'df'])
            ->from(LocalTables::TERMS_TABLE)
            ->where($bands)
            ->andFilterWhere(['siteId' => $siteId])
            ->limit(self::CORRECTION_CANDIDATE_LIMIT)
            ->all();

        foreach ($misspelled as $lexeme) {
            $ceiling = $ceilings[$lexeme];
            $length = strlen($lexeme);
            $bestTerm = null;
            $bestDf = -1;

            foreach ($candidates as $candidate) {
                $term = (string)$candidate['term'];
                if (abs(strlen($term) - $length) > $ceiling) {
                    continue;
                }
                if (levenshtein($term, $lexeme) > $ceiling) {
                    continue;
                }
                /* Ties break on document frequency: the commoner word is the likelier
                   intent. */
                $df = (int)$candidate['df'];
                if ($df > $bestDf) {
                    $bestDf = $df;
                    $bestTerm = $term;
                }
            }

            $cache->set($cachePrefix . $lexeme, $bestTerm ?? '', self::VARIANT_CACHE_TTL_SECONDS);
            if ($bestTerm !== null) {
                $variants[$lexeme] = $bestTerm;
            }
        }

        return $variants;
    }

    /**
     * Sum the weight of every boost rule whose phrases all appear in the query, with
     * their words adjacent.
     *
     * Reproduces what the pgvector side gets from `phraseto_tsquery` and the `<->`
     * operator: a rule "1 bedroom" must not fire for the query "2 bedroom 1 bathroom",
     * even though both of its words appear. Typo variants sit at the same position as
     * the word they correct, so a misspelling keeps the phrase intact without letting a
     * wrong guess lose the literal match.
     *
     * ponytail: siteId-filtered scan over the rules, matching BoostService's own
     * documented ceiling. A lexeme pre-filter column is the upgrade if a site ever has
     * tens of thousands of rules.
     *
     * @param string[] $lexemes
     * @param array<string, string> $variants
     * @return array<int, float> elementId => summed weight
     */
    private function boostMatch(array $lexemes, array $variants, ?int $siteId): array
    {
        if ($lexemes === []) {
            return [];
        }

        $rules = (new Query())
            ->select(['elementId', 'terms', 'weight'])
            ->from(LocalTables::BOOSTS_TABLE)
            ->andFilterWhere(['siteId' => $siteId])
            ->all();

        if ($rules === []) {
            return [];
        }

        /* Position => the lexemes acceptable there: the query's own word, plus its
           correction. 1-indexed to read like a tsvector's positions. */
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
            $phrases = json_decode((string)$rule['terms'], true);
            if (!is_array($phrases) || $phrases === []) {
                continue;
            }

            foreach ($phrases as $phrase) {
                if (!is_array($phrase) || $phrase === [] || !self::phraseMatches($phrase, $positions)) {
                    continue 2;
                }
            }

            $elementId = (int)$rule['elementId'];
            $boosts[$elementId] = ($boosts[$elementId] ?? 0.0) + (float)$rule['weight'];
        }

        return $boosts;
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
     * Fetch the excerpt text for the results that survived, by chunk key, in one query.
     *
     * Ranking never reads the chunk text, so it is fetched only for the handful of rows
     * that will actually be shown — the same reason the pgvector path returns chunk keys
     * from its vector query rather than bodies.
     */
    private function chunkContentFetcher(array $finalResults): ?callable
    {
        $keys = [];
        foreach ($finalResults as $result) {
            if (($result['chunk'] ?? null) !== null) {
                [$elementId, $rowSiteId, $chunkIndex] = $result['chunk'];
                /* Keyed by column name, not positional: the composite-IN builder reads
                   each row by column and silently emits NULLs for a plain list. */
                $keys[] = [
                    'elementId' => (int)$elementId,
                    'siteId' => (int)$rowSiteId,
                    'chunkIndex' => (int)$chunkIndex,
                ];
            }
        }

        if ($keys === []) {
            return null;
        }

        return static function() use ($keys): array {
            $rows = (new Query())
                ->select(['elementId', 'siteId', 'chunkIndex', 'body'])
                ->from(LocalTables::CHUNKS_TABLE)
                ->where(['in', ['elementId', 'siteId', 'chunkIndex'], $keys])
                ->all();

            $bodies = [];
            foreach ($rows as $row) {
                $bodies["{$row['elementId']}-{$row['siteId']}-{$row['chunkIndex']}"] = (string)$row['body'];
            }

            return $bodies;
        };
    }

    /**
     * Chunk count and mean chunk length for the site, which BM25 needs as N and avgdl.
     * Cached against the local store's token, so a write refreshes it.
     *
     * @return array{0: int, 1: float}
     */
    private function corpusStats(?int $siteId): array
    {
        $cache = Craft::$app->getCache();
        $key = 'smart_search_local_corpus_' . md5(
            SmartSearch::getInstance()->localIndexService->cacheToken() . '|' . ($siteId ?? '')
        );

        $stats = $cache->get($key);
        if (!is_array($stats)) {
            $row = (new Query())
                ->select(['total' => 'COUNT(*)', 'meanLength' => 'AVG([[tokenCount]])'])
                ->from(LocalTables::CHUNKS_TABLE)
                ->andFilterWhere(['siteId' => $siteId])
                ->one();

            $stats = [(int)($row['total'] ?? 0), max(1.0, (float)($row['meanLength'] ?? 1))];
            $cache->set($key, $stats, self::RANKING_CACHE_TTL_SECONDS);
        }

        return $stats;
    }

    /**
     * @param float[] $vector
     * @return float[]
     */
    private static function normalise(array $vector): array
    {
        $norm = 0.0;
        foreach ($vector as $component) {
            $norm += $component * $component;
        }
        $norm = sqrt($norm);

        if ($norm === 0.0) {
            return $vector;
        }

        foreach ($vector as $i => $component) {
            $vector[$i] = $component / $norm;
        }

        return $vector;
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

        $cache->set(self::SCAN_SAMPLE_KEY, $samples, 0);
    }

    /**
     * The recorded scans, oldest first. Entries from before the chunk count was recorded
     * are dropped rather than guessed at.
     *
     * @return array<array{ms: float, chunks: int}>
     */
    public static function scanSamples(): array
    {
        $samples = Craft::$app->getCache()->get(self::SCAN_SAMPLE_KEY);
        if (!is_array($samples)) {
            return [];
        }

        $shaped = [];
        foreach ($samples as $sample) {
            if (is_array($sample) && isset($sample['ms'], $sample['chunks'])) {
                $shaped[] = ['ms' => (float)$sample['ms'], 'chunks' => (int)$sample['chunks']];
            }
        }

        return $shaped;
    }

    /** Nearest-rank percentile of the recorded scan durations, or null with no samples. */
    public static function scanPercentile(int $percentile = 95): ?float
    {
        $durations = array_column(self::scanSamples(), 'ms');

        return self::nearestRank($durations, $percentile);
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

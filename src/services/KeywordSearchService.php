<?php

namespace ghoststreet\craftsmartsearch\services;

use Craft;
use craft\db\Query;
use ghoststreet\craftsmartsearch\SmartSearch;
use ghoststreet\craftsmartsearch\exceptions\SearchException;
use ghoststreet\craftsmartsearch\helpers\Logger;
use PDOException;
use yii\base\Component;

/**
 * Keyword scoring via PostgreSQL full-text search, with a title-match rerank.
 *
 * Each chunk is scored against the query with ts_rank_cd (length-normalized), parsed
 * via websearch_to_tsquery so quoted phrases and OR/- operators work. Multi-token
 * queries also get an ILIKE substring bonus on the chunk body. After ranking, an
 * in-PHP rerank adds a bonus proportional to the longest consecutive run of query
 * tokens found in the entry title — titles live in the main Craft DB (not the
 * vectors DB), so this can't be expressed as a SQL JOIN.
 */
class KeywordSearchService extends Component
{
    /** ts_rank_cd normalization flag: divide rank by document length. */
    private const TS_RANK_NORMALIZE_LENGTH = 32;

    /** Per extra token in the longest consecutive title match — bigram = +1.5, trigram = +3.0, etc. */
    private const TITLE_NGRAM_BONUS_PER_EXTRA_TOKEN = 1.5;

    /** Interpolated into SQL; anything outside this set falls back to 'simple'. */
    private const SUPPORTED_TS_CONFIGS = [
        'simple', 'arabic', 'armenian', 'basque', 'catalan', 'danish', 'dutch',
        'english', 'finnish', 'french', 'german', 'greek', 'hindi', 'hungarian',
        'indonesian', 'irish', 'italian', 'lithuanian', 'nepali', 'norwegian',
        'portuguese', 'romanian', 'russian', 'serbian', 'spanish', 'swedish',
        'tamil', 'turkish', 'yiddish',
    ];

    /**
     * @return array<int, array{elementId: int, siteId: int, keywordScore: float, content: string}>
     * @throws SearchException If the database query fails
     */
    public function calculateScores(string $query, ?int $siteId = null): array
    {
        $db = SmartSearch::getInstance()->databaseService->getConnection();
        $table = SmartSearch::getInstance()->databaseService->getQualifiedTable();
        $normalizedQuery = trim($query);

        if ($normalizedQuery === '') {
            return [];
        }

        $language = $this->resolveTextSearchConfig($siteId);
        $normalization = self::TS_RANK_NORMALIZE_LENGTH;

        // For single-keyword queries the ILIKE branch would over-match (e.g. "the") and add no relative signal.
        $tokenCount = count(preg_split('/\s+/', $normalizedQuery, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $applyPhraseBoost = $tokenCount >= 2;

        try {
            $contentTs = "to_tsvector('{$language}', COALESCE(content, ''))";
            $tsQuery = "websearch_to_tsquery('{$language}', :query)";

            $scoreExpr = "ts_rank_cd({$contentTs}, {$tsQuery}, {$normalization})";
            $whereExpr = "{$contentTs} @@ {$tsQuery}";

            if ($applyPhraseBoost) {
                // +1.0 is large relative to typical ts_rank_cd values (~0.0-0.5),
                // floating any chunk containing the exact phrase to the top of the keyword ranking.
                $scoreExpr .= " + (CASE WHEN content ILIKE :raw THEN 1.0 ELSE 0 END)";
                // OR'd into WHERE so exact substring matches still surface even when
                // websearch_to_tsquery returns empty (e.g. all-stopword queries).
                $whereExpr = "({$whereExpr} OR content ILIKE :raw)";
            }

            $params = [':query' => $normalizedQuery];

            if ($applyPhraseBoost) {
                $params[':raw'] = '%' . $normalizedQuery . '%';
            }

            // Best-effort typo expansion. Returns null whenever pg_trgm or the
            // dictionary isn't ready, so keyword search reads identically to before in
            // that case. When it returns an expression, OR it into the WHERE
            // and score so corrected matches contribute without displacing
            // exact-match ranking.
            $corrected = SmartSearch::getInstance()->queryCorrectorService->rewrite($normalizedQuery, $siteId);
            if ($corrected !== null) {
                $correctedTsQuery = "to_tsquery('{$language}', :corrected)";
                $whereExpr = "({$whereExpr} OR {$contentTs} @@ {$correctedTsQuery})";
                // Half-weight: a typo match should never outrank an exact-token match.
                $scoreExpr .= " + (0.5 * ts_rank_cd({$contentTs}, {$correctedTsQuery}, {$normalization}))";
                $params[':corrected'] = $corrected;

                Logger::debug('Keyword typo correction applied', [
                    'original' => $normalizedQuery,
                    'tsquery' => $corrected,
                ]);
            }

            $siteFilter = '';
            if ($siteId !== null) {
                $siteFilter = ' AND "siteId" = :siteId';
                $params[':siteId'] = $siteId;
            }

            $maxResults = SmartSearch::getInstance()->getSettings()->maxSemanticResults;

            // CTE + ROW_NUMBER keeps the *content* of the best-scoring chunk per
            // (elementId, siteId). Smart Search needs that content for excerpt rendering
            // when a result is keyword-only (not in the semantic top-N).
            $sql = "
                WITH scored AS (
                    SELECT
                        \"elementId\",
                        \"siteId\",
                        content,
                        {$scoreExpr} AS chunk_score
                    FROM {$table}
                    WHERE {$whereExpr}{$siteFilter}
                ), ranked AS (
                    SELECT
                        \"elementId\",
                        \"siteId\",
                        content,
                        chunk_score,
                        ROW_NUMBER() OVER (
                            PARTITION BY \"elementId\", \"siteId\"
                            ORDER BY chunk_score DESC
                        ) AS rn
                    FROM scored
                )
                SELECT \"elementId\", \"siteId\", chunk_score AS keyword_score, content
                FROM ranked
                WHERE rn = 1
                ORDER BY keyword_score DESC
                LIMIT {$maxResults}
            ";

            Logger::debug('Keyword query', [
                'query' => $normalizedQuery,
                'siteId' => $siteId,
                'maxResults' => $maxResults,
                'phraseBoost' => $applyPhraseBoost,
            ]);

            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            $rows = $stmt->fetchAll();

            Logger::debug('Keyword results', [
                'matchedElements' => count($rows),
            ]);

            $scores = [];
            foreach ($rows as $row) {
                $scores[] = [
                    'elementId' => (int)$row['elementId'],
                    'siteId' => (int)$row['siteId'],
                    'keywordScore' => (float)$row['keyword_score'],
                    'content' => (string)($row['content'] ?? ''),
                ];
            }

            return $this->applyTitleBoost($scores, $normalizedQuery);
        } catch (PDOException $e) {
            Logger::exception($e, 'calculateScores', ['query' => substr($query, 0, 50)]);
            throw SearchException::semanticSearchFailed('Keyword scoring failed', $e);
        }
    }

    /**
     * Rerank candidates by the longest consecutive run of query tokens found in the entry title.
     *
     * A bigram match adds +1.5, trigram +3.0, etc. Single-token matches add nothing
     * here — Keyword body scoring already accounts for individual word presence.
     *
     * @param array<int, array{elementId: int, siteId: int, keywordScore: float, content: string}> $scores
     * @return array<int, array{elementId: int, siteId: int, keywordScore: float, content: string}>
     */
    private function applyTitleBoost(array $scores, string $query): array
    {
        if (empty($scores)) {
            return $scores;
        }

        $tokens = array_values(array_filter(
            preg_split('/\s+/', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            static fn(string $t) => mb_strlen($t) >= 2
        ));

        if (count($tokens) < 2) {
            return $scores;
        }

        $ids = array_unique(array_map(static fn(array $s) => $s['elementId'], $scores));

        $rows = (new Query())
            ->select(['elementId', 'siteId', 'title'])
            ->from('{{%elements_sites}}')
            ->where(['elementId' => $ids])
            ->all();

        $titles = [];
        foreach ($rows as $row) {
            $titles[$row['elementId'] . '-' . $row['siteId']] = mb_strtolower((string)($row['title'] ?? ''));
        }

        $boostedCount = 0;
        $longestObserved = 0;
        foreach ($scores as &$score) {
            $title = $titles[$score['elementId'] . '-' . $score['siteId']] ?? '';
            if ($title === '') {
                continue;
            }

            $longest = $this->longestConsecutiveTokenMatch($tokens, $title);
            if ($longest >= 2) {
                $score['keywordScore'] += ($longest - 1) * self::TITLE_NGRAM_BONUS_PER_EXTRA_TOKEN;
                $boostedCount++;
                $longestObserved = max($longestObserved, $longest);
            }
        }
        unset($score);

        usort($scores, static fn(array $a, array $b) => $b['keywordScore'] <=> $a['keywordScore']);

        Logger::debug('Keyword title-boost', [
            'candidates' => count($scores),
            'titlesResolved' => count($titles),
            'titleBoosted' => $boostedCount,
            'longestMatch' => $longestObserved,
            'tokens' => $tokens,
        ]);

        return $scores;
    }

    /**
     * Find the length of the longest consecutive run of query tokens that appears as a
     * substring in $haystack. Returns 0 if no run of length >= 2 matches.
     *
     * @param string[] $tokens Lowercased query tokens in original order.
     * @param string $haystack Lowercased text to search.
     */
    private function longestConsecutiveTokenMatch(array $tokens, string $haystack): int
    {
        $n = count($tokens);
        for ($len = $n; $len >= 2; $len--) {
            for ($i = 0; $i + $len <= $n; $i++) {
                $ngram = implode(' ', array_slice($tokens, $i, $len));
                if (str_contains($haystack, $ngram)) {
                    return $len;
                }
            }
        }
        return 0;
    }

    /**
     * Map the Craft site language (e.g. "en-US") to a PostgreSQL text search config (e.g. "english").
     */
    private function resolveTextSearchConfig(?int $siteId): string
    {
        $site = $siteId !== null
            ? Craft::$app->getSites()->getSiteById($siteId)
            : Craft::$app->getSites()->getPrimarySite();

        $locale = $site->language ?? 'en';
        $name = strtolower(\Locale::getDisplayLanguage($locale, 'en'));

        return in_array($name, self::SUPPORTED_TS_CONFIGS, true) ? $name : 'simple';
    }
}

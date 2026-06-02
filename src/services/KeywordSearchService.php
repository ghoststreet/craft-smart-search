<?php

namespace ghoststreet\craftsmartsearch\services;

use Craft;
use ghoststreet\craftsmartsearch\exceptions\SearchException;
use ghoststreet\craftsmartsearch\helpers\Logger;
use ghoststreet\craftsmartsearch\helpers\TimingProfiler;
use ghoststreet\craftsmartsearch\SmartSearch;
use Locale;
use PDOException;
use yii\base\Component;

/**
 * Keyword scoring via the stored `tsv` column on the vectors table.
 *
 * The tsv is `setweight(A, title) || setweight(B, body)`, maintained by a
 * Postgres trigger. ts_rank_cd uses the A/B weights so title hits naturally
 * outrank body hits. Typo correction is OR'd in via the dictionary-based
 * corrector when available.
 */
class KeywordSearchService extends Component
{
    /** Interpolated into SQL; anything outside this set falls back to 'simple'. */
    public const SUPPORTED_TS_CONFIGS = [
        'simple', 'arabic', 'armenian', 'basque', 'catalan', 'danish', 'dutch',
        'english', 'finnish', 'french', 'german', 'greek', 'hindi', 'hungarian',
        'indonesian', 'irish', 'italian', 'lithuanian', 'nepali', 'norwegian',
        'portuguese', 'romanian', 'russian', 'serbian', 'spanish', 'swedish',
        'tamil', 'turkish', 'yiddish',
    ];

    /**
     * Resolves from the Craft site's language (e.g. `en-US`, `en-GB`, `en-AU` all
     * collapse to `english`). Used at index time to populate the per-row `language`
     * column and at query time to build the tsquery.
     */
    public static function resolveLanguage(?int $siteId = null): string
    {
        $site = $siteId !== null
            ? Craft::$app->getSites()->getSiteById($siteId)
            : Craft::$app->getSites()->getPrimarySite();

        $locale = $site->language ?? 'en';
        $name = strtolower(Locale::getDisplayLanguage($locale, 'en'));

        return in_array($name, self::SUPPORTED_TS_CONFIGS, true) ? $name : 'simple';
    }

    /**
     * @return list<array{elementId: int, siteId: int, keywordScore: float, content: string}>
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

        $language = self::resolveLanguage($siteId);

        try {
            $tokens = preg_split('/\s+/', $normalizedQuery, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $orQuery = implode(' OR ', $tokens);

            $tsQuery = "websearch_to_tsquery('{$language}', :query)";
            $whereExpr = "tsv @@ {$tsQuery}";
            $scoreExpr = "ts_rank_cd('{0.05, 0.1, 0.2, 1.0}'::float4[], tsv, {$tsQuery}, 32)";
            $params = [':query' => $orQuery];

            $corrected = TimingProfiler::profile(
                'Keyword corrector lookup',
                fn() => SmartSearch::getInstance()->queryCorrectorService->rewrite($normalizedQuery, $siteId)
            );
            if ($corrected !== null) {
                $whereExpr = "({$whereExpr} OR tsv @@ (:corrected)::tsquery)";
                $scoreExpr .= " + (0.5 * ts_rank_cd('{0.05, 0.1, 0.2, 1.0}'::float4[], tsv, (:corrected)::tsquery, 32))";
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

            $maxResults = (int)SmartSearch::getInstance()->getSettings()->maxSemanticResults;

            $sql = "
                SELECT \"elementId\", \"siteId\", content, keyword_score
                FROM (
                    SELECT DISTINCT ON (\"elementId\", \"siteId\")
                        \"elementId\",
                        \"siteId\",
                        body AS content,
                        {$scoreExpr} AS keyword_score
                    FROM {$table}
                    WHERE {$whereExpr}{$siteFilter}
                    ORDER BY \"elementId\", \"siteId\", {$scoreExpr} DESC
                ) best_per_entry
                ORDER BY keyword_score DESC
                LIMIT {$maxResults}
            ";

            Logger::debug('Keyword query', [
                'query' => $normalizedQuery,
                'siteId' => $siteId,
                'maxResults' => $maxResults,
            ]);

            $rows = TimingProfiler::profile(
                'Keyword main SQL',
                function() use ($db, $sql, $params) {
                    $stmt = $db->prepare($sql);
                    $stmt->execute($params);
                    return $stmt->fetchAll();
                }
            );

            Logger::debug('Keyword results', ['matchedElements' => count($rows)]);

            $scores = [];
            foreach ($rows as $row) {
                $scores[] = [
                    'elementId' => (int)$row['elementId'],
                    'siteId' => (int)$row['siteId'],
                    'keywordScore' => (float)$row['keyword_score'],
                    'content' => (string)($row['content'] ?? ''),
                ];
            }
            return $scores;
        } catch (PDOException $e) {
            Logger::exception($e, 'calculateScores', ['query' => substr($query, 0, 50)]);
            throw SearchException::semanticSearchFailed('Keyword scoring failed', $e);
        }
    }
}

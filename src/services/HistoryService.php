<?php

namespace ghoststreet\craftsmartsearch\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use ghoststreet\craftsmartsearch\SmartSearch;
use ghoststreet\craftsmartsearch\helpers\Logger;
use ghoststreet\craftsmartsearch\helpers\PricingTable;
use ghoststreet\craftsmartsearch\jobs\PruneHistoryJob;
use ghoststreet\craftsmartsearch\records\SearchHistoryDetailsRecord;
use ghoststreet\craftsmartsearch\records\SearchHistoryStatsRecord;
use Throwable;
use yii\base\Component;

class HistoryService extends Component
{
    public const STATS_TABLE = '{{%aisearch_history_stats}}';
    public const DETAILS_TABLE = '{{%aisearch_history_details}}';

    private const PRUNE_GUARD_KEY = 'aisearch.history.lastPrune';

    /**
     * Insert a search history row (stats + details). Never throws — failures are logged
     * but never break the search response.
     *
     * Expected keys in $data:
     *   requestId, type ('semantic'|'rag'), query, userId, siteId,
     *   resultsCount, results (array), summary (?string), confidence (?string),
     *   embeddingModel, ragModel, embeddingTokens, ragInputTokens, ragOutputTokens,
     *   durationMs, embeddingCached (bool), errorMessage (?string)
     */
    public function record(array $data): void
    {
        $embeddingTokens = (int)($data['embeddingTokens'] ?? 0);
        $ragIn = (int)($data['ragInputTokens'] ?? 0);
        $ragOut = (int)($data['ragOutputTokens'] ?? 0);
        $total = $embeddingTokens + $ragIn + $ragOut;

        $embedCost = PricingTable::calculateCost($data['embeddingModel'] ?? null, $embeddingTokens, 0);
        $ragCost = PricingTable::calculateCost($data['ragModel'] ?? null, $ragIn, $ragOut);
        $totalCost = round($embedCost + $ragCost, 6);

        $errorMessage = $data['errorMessage'] ?? null;

        try {
            $db = Craft::$app->getDb();
            $transaction = $db->beginTransaction();

            try {
                $stats = new SearchHistoryStatsRecord();
                $stats->requestId = (string)($data['requestId'] ?? '');
                $stats->type = (string)($data['type'] ?? 'semantic');
                $stats->userId = $data['userId'] ?? null;
                $stats->siteId = $data['siteId'] ?? null;
                $stats->resultsCount = (int)($data['resultsCount'] ?? 0);
                $stats->embeddingModel = $data['embeddingModel'] ?? null;
                $stats->ragModel = $data['ragModel'] ?? null;
                $stats->embeddingTokens = $embeddingTokens;
                $stats->ragInputTokens = $ragIn;
                $stats->ragOutputTokens = $ragOut;
                $stats->totalTokens = $total;
                $stats->cost = (string)$totalCost;
                $stats->durationMs = (int)($data['durationMs'] ?? 0);
                $stats->embeddingCached = (bool)($data['embeddingCached'] ?? false);
                $stats->hasError = $errorMessage !== null;
                $stats->save(false);

                $details = new SearchHistoryDetailsRecord();
                $details->statsId = $stats->id;
                $details->requestId = $stats->requestId;
                $details->query = (string)($data['query'] ?? '');
                $details->results = $data['results'] ?? null;
                $details->summary = $data['summary'] ?? null;
                $details->confidence = $data['confidence'] ?? null;
                $details->errorMessage = $errorMessage;
                $details->save(false);

                $transaction->commit();
            } catch (Throwable $e) {
                $transaction->rollBack();
                throw $e;
            }
        } catch (Throwable $e) {
            Logger::exception($e, 'history.record');
            return;
        }

        $settings = SmartSearch::getInstance()->getSettings();
        $this->maybeQueuePrune((int)$settings->historyRetentionDays);
    }

    /**
     * Aggregate stats for the header. Always sourced from the (permanent) stats table.
     */
    public function getStats(?int $days = null): array
    {
        $query = (new Query())->from(self::STATS_TABLE);

        if ($days !== null && $days > 0) {
            $query->andWhere(['>=', 'dateCreated', Db::prepareDateForDb(new \DateTime("-{$days} days"))]);
        }

        $row = (clone $query)
            ->select([
                'searches' => 'COUNT(*)',
                'tokens' => 'COALESCE(SUM(totalTokens), 0)',
                'embeddingTokens' => 'COALESCE(SUM(embeddingTokens), 0)',
                'llmTokens' => 'COALESCE(SUM(ragInputTokens + ragOutputTokens), 0)',
                'cost' => 'COALESCE(SUM(cost), 0)',
                'avgDuration' => 'COALESCE(AVG(durationMs), 0)',
                'errorCount' => 'SUM(CASE WHEN hasError = 1 OR hasError = TRUE THEN 1 ELSE 0 END)',
            ])
            ->one();

        $byType = (clone $query)
            ->select([
                'type',
                'cnt' => 'COUNT(*)',
                'tokens' => 'COALESCE(SUM(totalTokens), 0)',
                'cost' => 'COALESCE(SUM(cost), 0)',
            ])
            ->groupBy(['type'])
            ->all();

        $byTypeMap = [];
        foreach ($byType as $r) {
            $byTypeMap[$r['type']] = [
                'count' => (int)$r['cnt'],
                'tokens' => (int)$r['tokens'],
                'cost' => (float)$r['cost'],
            ];
        }

        $searches = (int)($row['searches'] ?? 0);
        $cost = (float)($row['cost'] ?? 0);

        return [
            'searches' => $searches,
            'tokens' => (int)($row['tokens'] ?? 0),
            'embeddingTokens' => (int)($row['embeddingTokens'] ?? 0),
            'llmTokens' => (int)($row['llmTokens'] ?? 0),
            'cost' => round($cost, 6),
            'avgDurationMs' => (int)round((float)($row['avgDuration'] ?? 0)),
            'avgCostPerSearch' => $searches > 0 ? round($cost / $searches, 6) : 0.0,
            'errorCount' => (int)($row['errorCount'] ?? 0),
            'byType' => $byTypeMap,
        ];
    }

    public function paginate(int $page = 1, int $perPage = 25, array $filters = []): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $base = (new Query())
            ->from(['s' => self::STATS_TABLE])
            ->leftJoin(['d' => self::DETAILS_TABLE], '[[d.statsId]] = [[s.id]]');

        if (!empty($filters['type'])) {
            $base->andWhere(['s.type' => $filters['type']]);
        }
        if (!empty($filters['days']) && (int)$filters['days'] > 0) {
            $days = (int)$filters['days'];
            $base->andWhere(['>=', 's.dateCreated', Db::prepareDateForDb(new \DateTime("-{$days} days"))]);
        }
        if (!empty($filters['errorsOnly'])) {
            $base->andWhere(['s.hasError' => true]);
        }
        if (!empty($filters['siteId'])) {
            $base->andWhere(['s.siteId' => (int)$filters['siteId']]);
        }

        $total = (clone $base)->count('*');

        $items = (clone $base)
            ->select([
                's.id', 's.requestId', 's.type', 's.resultsCount', 's.embeddingModel', 's.ragModel',
                's.embeddingTokens', 's.ragInputTokens', 's.ragOutputTokens', 's.totalTokens',
                's.cost', 's.durationMs', 's.embeddingCached', 's.hasError', 's.dateCreated',
                'd.query', 'd.summary', 'd.confidence', 'd.errorMessage',
                'detailsId' => 'd.id',
            ])
            ->orderBy(['s.dateCreated' => SORT_DESC])
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->all();

        return [
            'items' => $items,
            'total' => (int)$total,
            'page' => $page,
            'perPage' => $perPage,
            'pages' => (int)ceil($total / $perPage),
        ];
    }

    /**
     * Delete details rows older than $days. Stats untouched.
     */
    public function pruneOlderThan(int $days): int
    {
        $cutoff = Db::prepareDateForDb(new \DateTime("-{$days} days"));
        $deleted = Craft::$app->getDb()->createCommand()
            ->delete(self::DETAILS_TABLE, ['<', 'dateCreated', $cutoff])
            ->execute();

        Logger::info("HistoryService: pruned {$deleted} details rows older than {$days} days");
        Craft::$app->getCache()->set(self::PRUNE_GUARD_KEY, time(), 86400);

        return $deleted;
    }

    public function detailsCount(): int
    {
        return (int)(new Query())->from(self::DETAILS_TABLE)->count('*');
    }

    /**
     * Most-frequent search keywords. Grouped case-insensitively on the trimmed query.
     */
    public function getTopKeywords(?int $days = null, ?int $siteId = null, int $limit = 10): array
    {
        return $this->aggregateKeywords($days, $siteId, $limit, false);
    }

    /**
     * Most-frequent queries that returned zero results.
     */
    public function getZeroResultQueries(?int $days = null, ?int $siteId = null, int $limit = 10): array
    {
        return $this->aggregateKeywords($days, $siteId, $limit, true);
    }

    /**
     * Keywords whose frequency rose in the last $windowDays vs the prior $windowDays.
     */
    public function getTrendingKeywords(?int $siteId = null, int $windowDays = 7, int $limit = 10): array
    {
        $limit = max(1, min(200, $limit));
        $windowDays = max(1, $windowDays);
        $now = new \DateTime();
        $recentCutoff = Db::prepareDateForDb((clone $now)->modify("-{$windowDays} days"));
        $priorCutoff = Db::prepareDateForDb((clone $now)->modify('-' . ($windowDays * 2) . ' days'));

        $recent = $this->groupedCounts($recentCutoff, null, $siteId, false, true);
        $prior = $this->groupedCounts($priorCutoff, $recentCutoff, $siteId, false, true);

        $byKey = [];
        foreach ($recent as $r) {
            $byKey[$r['k']] = [
                'query' => $r['query'],
                'recent' => (int)$r['hits'],
                'prior' => 0,
            ];
        }
        foreach ($prior as $r) {
            if (isset($byKey[$r['k']])) {
                $byKey[$r['k']]['prior'] = (int)$r['hits'];
            } else {
                $byKey[$r['k']] = [
                    'query' => $r['query'],
                    'recent' => 0,
                    'prior' => (int)$r['hits'],
                ];
            }
        }

        $rows = [];
        foreach ($byKey as $row) {
            $delta = $row['recent'] - $row['prior'];
            if ($delta <= 0) {
                continue;
            }
            $row['delta'] = $delta;
            $row['growthPct'] = $row['prior'] > 0 ? round((($row['recent'] - $row['prior']) / $row['prior']) * 100, 1) : null;
            $rows[] = $row;
        }

        usort($rows, static fn($a, $b) => $b['delta'] <=> $a['delta']);

        return array_slice($rows, 0, $limit);
    }

    /**
     * Daily-bucketed search stats for the last $days. Returns one row per day in
     * chronological order, with zero-filled gaps so charts can render contiguous
     * timelines. Grouping is done in PHP to stay portable across MySQL / Postgres.
     *
     * @return list<array{date: string, searches: int, tokens: int, cost: float, avgMs: int, p95Ms: int, errors: int, cacheHits: int, zeroResults: int}>
     */
    public function getDailySeries(int $days = 30, ?string $type = null, ?int $siteId = null): array
    {
        $days = max(1, min(365, $days));
        $cutoff = Db::prepareDateForDb(new \DateTime("-{$days} days"));

        $q = (new Query())
            ->from(self::STATS_TABLE)
            ->select(['dateCreated', 'type', 'siteId', 'totalTokens', 'cost', 'durationMs', 'hasError', 'embeddingCached', 'resultsCount'])
            ->andWhere(['>=', 'dateCreated', $cutoff]);

        if ($type !== null) {
            $q->andWhere(['type' => $type]);
        }
        if ($siteId !== null) {
            $q->andWhere(['siteId' => $siteId]);
        }

        $rows = $q->all();

        $buckets = [];
        foreach ($rows as $r) {
            $date = substr((string)$r['dateCreated'], 0, 10);
            if (!isset($buckets[$date])) {
                $buckets[$date] = [
                    'date' => $date,
                    'searches' => 0,
                    'tokens' => 0,
                    'cost' => 0.0,
                    'durations' => [],
                    'errors' => 0,
                    'cacheHits' => 0,
                    'zeroResults' => 0,
                ];
            }
            $buckets[$date]['searches']++;
            $buckets[$date]['tokens'] += (int)$r['totalTokens'];
            $buckets[$date]['cost'] += (float)$r['cost'];
            $buckets[$date]['durations'][] = (int)$r['durationMs'];
            if ($r['hasError']) {
                $buckets[$date]['errors']++;
            }
            if ($r['embeddingCached']) {
                $buckets[$date]['cacheHits']++;
            }
            if ((int)$r['resultsCount'] === 0) {
                $buckets[$date]['zeroResults']++;
            }
        }

        $series = [];
        $cursor = new \DateTime("-{$days} days");
        $end = new \DateTime('today');
        while ($cursor <= $end) {
            $key = $cursor->format('Y-m-d');
            $b = $buckets[$key] ?? null;
            $avg = 0;
            $p95 = 0;
            if ($b !== null && $b['durations']) {
                $avg = (int)round(array_sum($b['durations']) / count($b['durations']));
                $sorted = $b['durations'];
                sort($sorted);
                $idx = (int)floor(0.95 * (count($sorted) - 1));
                $p95 = $sorted[$idx];
            }
            $series[] = [
                'date' => $key,
                'searches' => $b['searches'] ?? 0,
                'tokens' => $b['tokens'] ?? 0,
                'cost' => round($b['cost'] ?? 0.0, 6),
                'avgMs' => $avg,
                'p95Ms' => $p95,
                'errors' => $b['errors'] ?? 0,
                'cacheHits' => $b['cacheHits'] ?? 0,
                'zeroResults' => $b['zeroResults'] ?? 0,
            ];
            $cursor->modify('+1 day');
        }

        return $series;
    }

    /**
     * True overall percentile of durationMs across raw rows in the window. Portable across MySQL/Postgres:
     * counts qualifying rows, then SELECTs the value at the percentile offset.
     * Returns null when no rows.
     */
    public function getOverallPercentile(int $days, float $percentile = 0.95, ?int $siteId = null): ?int
    {
        $percentile = max(0.0, min(1.0, $percentile));
        $cutoff = Db::prepareDateForDb(new \DateTime("-{$days} days"));

        $base = (new Query())
            ->from(self::STATS_TABLE)
            ->andWhere(['>=', 'dateCreated', $cutoff])
            ->andWhere(['>', 'durationMs', 0]);
        if ($siteId !== null) {
            $base->andWhere(['siteId' => $siteId]);
        }

        $count = (int)(clone $base)->count('*');
        if ($count === 0) {
            return null;
        }

        $offset = (int)floor($percentile * ($count - 1));
        $row = (clone $base)
            ->select(['durationMs'])
            ->orderBy(['durationMs' => SORT_ASC])
            ->offset($offset)
            ->limit(1)
            ->one();

        return $row ? (int)$row['durationMs'] : null;
    }

    /**
     * Embedding cache hit rate over the last $days, 0..1. Returns null when no searches.
     */
    public function getCacheHitRate(int $days = 30): ?float
    {
        $row = (new Query())
            ->from(self::STATS_TABLE)
            ->andWhere(['>=', 'dateCreated', Db::prepareDateForDb(new \DateTime("-{$days} days"))])
            ->select([
                'total' => 'COUNT(*)',
                'hits' => 'SUM(CASE WHEN embeddingCached = 1 OR embeddingCached = TRUE THEN 1 ELSE 0 END)',
            ])
            ->one();

        $total = (int)($row['total'] ?? 0);
        if ($total === 0) {
            return null;
        }
        return round((int)$row['hits'] / $total, 4);
    }

    /**
     * Share of searches in the window that returned zero results, 0..1. Null when no searches.
     */
    public function getZeroResultRate(int $days = 30): ?float
    {
        $row = (new Query())
            ->from(self::STATS_TABLE)
            ->andWhere(['>=', 'dateCreated', Db::prepareDateForDb(new \DateTime("-{$days} days"))])
            ->select([
                'total' => 'COUNT(*)',
                'zeros' => 'SUM(CASE WHEN resultsCount = 0 THEN 1 ELSE 0 END)',
            ])
            ->one();

        $total = (int)($row['total'] ?? 0);
        if ($total === 0) {
            return null;
        }
        return round((int)$row['zeros'] / $total, 4);
    }

    /**
     * Queries whose average response time exceeds $thresholdMs in the window.
     * Returns rows: [k, query, hits, avgDurationMs, lastSeen].
     */
    public function getSlowQueries(?int $days = 30, ?int $siteId = null, int $limit = 10, int $thresholdMs = 1500): array
    {
        $limit = max(1, min(50, $limit));
        $cutoff = ($days !== null && $days > 0)
            ? Db::prepareDateForDb(new \DateTime("-{$days} days"))
            : null;

        $q = (new Query())
            ->from(['d' => self::DETAILS_TABLE])
            ->innerJoin(['s' => self::STATS_TABLE], '[[s.id]] = [[d.statsId]]')
            ->select([
                'k' => 'LOWER(TRIM([[d.query]]))',
                'query' => 'MIN([[d.query]])',
                'hits' => 'COUNT(*)',
                'avgDurationMs' => 'AVG([[s.durationMs]])',
                'lastSeen' => 'MAX([[s.dateCreated]])',
            ])
            ->andWhere(['not', ['d.query' => null]])
            ->andWhere(['<>', 'd.query', ''])
            ->groupBy(['k'])
            ->having(['>=', 'AVG([[s.durationMs]])', $thresholdMs])
            ->orderBy(['avgDurationMs' => SORT_DESC])
            ->limit($limit);

        if ($cutoff !== null) {
            $q->andWhere(['>=', 's.dateCreated', $cutoff]);
        }

        if ($siteId !== null) {
            $q->andWhere(['s.siteId' => $siteId]);
        }

        return $q->all();
    }

    /**
     * Most recent search errors with the query text and message.
     * Returns rows: [id, type, dateCreated, query, errorMessage].
     */
    public function getRecentErrors(int $limit = 5): array
    {
        $limit = max(1, min(50, $limit));
        return (new Query())
            ->from(['s' => self::STATS_TABLE])
            ->leftJoin(['d' => self::DETAILS_TABLE], '[[d.statsId]] = [[s.id]]')
            ->select([
                's.id', 's.type', 's.dateCreated',
                'query' => 'd.query',
                'errorMessage' => 'd.errorMessage',
            ])
            ->andWhere(['s.hasError' => true])
            ->orderBy(['s.dateCreated' => SORT_DESC])
            ->limit($limit)
            ->all();
    }

    /**
     * Distinct siteIds that have history rows, hydrated with names for the filter dropdown.
     */
    public function getAvailableSites(): array
    {
        $rows = (new Query())
            ->from(self::STATS_TABLE)
            ->select(['siteId'])
            ->where(['not', ['siteId' => null]])
            ->distinct()
            ->all();

        $sites = [];
        $sitesService = Craft::$app->getSites();
        foreach ($rows as $r) {
            $id = (int)$r['siteId'];
            $site = $sitesService->getSiteById($id);
            $sites[] = [
                'id' => $id,
                'name' => $site?->name ?: $site?->handle,
                'handle' => $site?->handle,
            ];
        }
        usort($sites, static fn($a, $b) => strcasecmp($a['name'], $b['name']));

        return $sites;
    }

    /**
     * Total searches over the given days+site filter, counted on the same
     * DETAILS⨝STATS non-empty-query universe that getTopKeywords hits are
     * drawn from — so per-query share percentages sum to ~100%.
     */
    public function countSearches(?int $days, ?int $siteId): int
    {
        $cutoff = ($days !== null && $days > 0)
            ? Db::prepareDateForDb(new \DateTime("-{$days} days"))
            : null;

        $q = (new Query())
            ->from(['d' => self::DETAILS_TABLE])
            ->innerJoin(['s' => self::STATS_TABLE], '[[s.id]] = [[d.statsId]]')
            ->andWhere(['not', ['d.query' => null]])
            ->andWhere(['<>', 'd.query', '']);

        if ($cutoff !== null) {
            $q->andWhere(['>=', 's.dateCreated', $cutoff]);
        }
        if ($siteId !== null) {
            $q->andWhere(['s.siteId' => $siteId]);
        }

        return (int)$q->count('*');
    }

    private function aggregateKeywords(?int $days, ?int $siteId, int $limit, bool $zeroOnly): array
    {
        $limit = max(1, min(50, $limit));
        $cutoff = ($days !== null && $days > 0)
            ? Db::prepareDateForDb(new \DateTime("-{$days} days"))
            : null;

        return $this->groupedCounts($cutoff, null, $siteId, $zeroOnly, false, $limit);
    }

    /**
     * Paginated variant of getTopKeywords / getZeroResultQueries.
     * Returns { items, total, page, perPage, pages }.
     */
    public function paginateKeywords(?int $days, ?int $siteId, bool $zeroOnly, int $page = 1, int $perPage = 25): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $cutoff = ($days !== null && $days > 0)
            ? Db::prepareDateForDb(new \DateTime("-{$days} days"))
            : null;

        $total = $this->groupedCountsTotal($cutoff, null, $siteId, $zeroOnly);
        $items = $this->groupedCounts($cutoff, null, $siteId, $zeroOnly, false, $perPage, ($page - 1) * $perPage);

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'pages' => (int)ceil($total / $perPage),
        ];
    }

    /**
     * Paginated variant of getTrendingKeywords. The ranked list is limited to
     * the top $cap movers, then paginated in PHP.
     * Returns { items, total, page, perPage, pages }.
     */
    public function paginateTrending(?int $siteId, int $windowDays, int $page = 1, int $perPage = 25, int $cap = 100): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $all = $this->getTrendingKeywords($siteId, $windowDays, $cap);
        $total = count($all);
        $items = array_slice($all, ($page - 1) * $perPage, $perPage);

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'pages' => (int)ceil($total / $perPage),
        ];
    }

    private function groupedCountsTotal(?string $cutoffFrom, ?string $cutoffTo, ?int $siteId, bool $zeroOnly): int
    {
        $sub = (new Query())
            ->from(['d' => self::DETAILS_TABLE])
            ->innerJoin(['s' => self::STATS_TABLE], '[[s.id]] = [[d.statsId]]')
            ->select(['k' => 'LOWER(TRIM([[d.query]]))'])
            ->andWhere(['not', ['d.query' => null]])
            ->andWhere(['<>', 'd.query', ''])
            ->groupBy(['k']);

        if ($cutoffFrom !== null) {
            $sub->andWhere(['>=', 's.dateCreated', $cutoffFrom]);
        }
        if ($cutoffTo !== null) {
            $sub->andWhere(['<', 's.dateCreated', $cutoffTo]);
        }
        if ($siteId !== null) {
            $sub->andWhere(['s.siteId' => $siteId]);
        }
        if ($zeroOnly) {
            $sub->andWhere(['s.resultsCount' => 0]);
        }

        return (int)(new Query())->from(['x' => $sub])->count('*');
    }

    /**
     * Shared GROUP BY helper. Returns rows: [k, query, hits, zeroHits, lastSeen].
     */
    private function groupedCounts(
        ?string $cutoffFrom,
        ?string $cutoffTo,
        ?int $siteId,
        bool $zeroOnly,
        bool $minimal = false,
        ?int $limit = null,
        int $offset = 0
    ): array {
        $select = [
            'k' => 'LOWER(TRIM([[d.query]]))',
            'query' => 'MIN([[d.query]])',
            'hits' => 'COUNT(*)',
        ];
        if (!$minimal) {
            $select['zeroHits'] = 'SUM(CASE WHEN [[s.resultsCount]] = 0 THEN 1 ELSE 0 END)';
            $select['avgResults'] = 'AVG([[s.resultsCount]])';
            $select['avgDurationMs'] = 'AVG([[s.durationMs]])';
            $select['errors'] = 'SUM(CASE WHEN [[s.hasError]] = 1 THEN 1 ELSE 0 END)';
            $select['lastSeen'] = 'MAX([[s.dateCreated]])';
        }

        $q = (new Query())
            ->from(['d' => self::DETAILS_TABLE])
            ->innerJoin(['s' => self::STATS_TABLE], '[[s.id]] = [[d.statsId]]')
            ->select($select)
            ->andWhere(['not', ['d.query' => null]])
            ->andWhere(['<>', 'd.query', ''])
            ->groupBy(['k'])
            ->orderBy(['hits' => SORT_DESC]);

        if ($cutoffFrom !== null) {
            $q->andWhere(['>=', 's.dateCreated', $cutoffFrom]);
        }
        if ($cutoffTo !== null) {
            $q->andWhere(['<', 's.dateCreated', $cutoffTo]);
        }
        if ($siteId !== null) {
            $q->andWhere(['s.siteId' => $siteId]);
        }
        if ($zeroOnly) {
            $q->andWhere(['s.resultsCount' => 0]);
        }
        if ($limit !== null) {
            $q->limit($limit);
        }
        if ($offset > 0) {
            $q->offset($offset);
        }

        return $q->all();
    }

    private function maybeQueuePrune(int $retentionDays): void
    {
        $last = Craft::$app->getCache()->get(self::PRUNE_GUARD_KEY);
        if ($last !== false) {
            return;
        }

        // set guard immediately so we don't enqueue twice
        Craft::$app->getCache()->set(self::PRUNE_GUARD_KEY, time(), 86400);

        try {
            Craft::$app->getQueue()->push(new PruneHistoryJob([
                'retentionDays' => $retentionDays,
            ]));
        } catch (Throwable $e) {
            Logger::exception($e, 'history.queuePrune');
        }
    }
}

<?php

namespace ghoststreet\craftsmartsearch\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use DateTime;
use ghoststreet\craftsmartsearch\helpers\Logger;
use ghoststreet\craftsmartsearch\helpers\PricingTable;
use ghoststreet\craftsmartsearch\models\SearchHistoryEntry;
use ghoststreet\craftsmartsearch\records\SearchHistoryRecord;
use Throwable;
use yii\base\Component;

class HistoryService extends Component
{
    /**
     * Insert a search history row. Never throws — failures are logged
     * but never break the search response.
     */
    public function record(SearchHistoryEntry $entry): void
    {
        try {
            $row = new SearchHistoryRecord();
            $row->requestId = $entry->requestId;
            $row->type = $entry->type;
            $row->query = $entry->query;
            $row->siteId = $entry->siteId;
            $row->resultsCount = $entry->resultsCount;
            $row->embeddingModel = $entry->embeddingModel;
            $row->aiAnswerModel = $entry->aiAnswerModel;
            $row->embeddingTokens = $entry->embeddingTokens;
            $row->aiAnswerInputTokens = $entry->aiAnswerInputTokens;
            $row->aiAnswerOutputTokens = $entry->aiAnswerOutputTokens;
            $row->totalTokens = $entry->embeddingTokens + $entry->aiAnswerInputTokens + $entry->aiAnswerOutputTokens;
            $row->cost = (string)PricingTable::costForUsage(
                $entry->embeddingModel,
                $entry->embeddingTokens,
                $entry->aiAnswerModel,
                $entry->aiAnswerInputTokens,
                $entry->aiAnswerOutputTokens,
            );
            $row->durationMs = $entry->durationMs;
            $row->embeddingCached = $entry->embeddingCached;
            $row->hasError = $entry->errorMessage !== null;
            $row->errorMessage = $entry->errorMessage;
            $row->save(false);
        } catch (Throwable $e) {
            Logger::exception($e, 'history.record');
        }
    }

    /**
     * Aggregate stats for the header.
     */
    public function getStats(?int $days = null): array
    {
        $query = (new Query())->from(SearchHistoryRecord::tableName());

        if ($cutoff = $this->cutoff($days)) {
            $query->andWhere(['>=', 'dateCreated', $cutoff]);
        }

        $row = (clone $query)
            ->select([
                'searches' => 'COUNT(*)',
                'embeddingTokens' => 'COALESCE(SUM(embeddingTokens), 0)',
                'llmTokens' => 'COALESCE(SUM(aiAnswerInputTokens + aiAnswerOutputTokens), 0)',
                'cost' => 'COALESCE(SUM(cost), 0)',
                'avgDuration' => 'COALESCE(AVG(durationMs), 0)',
                'errorCount' => 'SUM(CASE WHEN hasError = 1 OR hasError = TRUE THEN 1 ELSE 0 END)',
            ])
            ->one();

        return [
            'searches' => (int)($row['searches'] ?? 0),
            'embeddingTokens' => (int)($row['embeddingTokens'] ?? 0),
            'llmTokens' => (int)($row['llmTokens'] ?? 0),
            'cost' => round((float)($row['cost'] ?? 0), 6),
            'avgDurationMs' => (int)round((float)($row['avgDuration'] ?? 0)),
            'errorCount' => (int)($row['errorCount'] ?? 0),
        ];
    }

    public function paginate(int $page = 1, int $perPage = 25, array $filters = []): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $base = (new Query())->from(SearchHistoryRecord::tableName());

        if (!empty($filters['type'])) {
            $base->andWhere(['type' => $filters['type']]);
        }
        if (!empty($filters['days']) && (int)$filters['days'] > 0) {
            $base->andWhere(['>=', 'dateCreated', $this->cutoff((int)$filters['days'])]);
        }
        if (!empty($filters['errorsOnly'])) {
            $base->andWhere(['hasError' => true]);
        }
        if (!empty($filters['siteId'])) {
            $base->andWhere(['siteId' => (int)$filters['siteId']]);
        }

        $total = (int)(clone $base)->count('*');

        $items = (clone $base)
            ->select([
                'id', 'requestId', 'type', 'query', 'resultsCount', 'embeddingModel', 'aiAnswerModel',
                'embeddingTokens', 'aiAnswerInputTokens', 'aiAnswerOutputTokens', 'totalTokens',
                'cost', 'durationMs', 'embeddingCached', 'hasError', 'errorMessage', 'dateCreated',
            ])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->all();

        return $this->paginated($items, $total, $page, $perPage);
    }

    public function count(): int
    {
        return (int)(new Query())->from(SearchHistoryRecord::tableName())->count('*');
    }

    /**
     * Most-frequent search keywords. Grouped case-insensitively on the trimmed query.
     */
    public function getTopKeywords(?int $days = null, ?int $siteId = null, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        return $this->groupedCounts($this->cutoff($days), null, $siteId, false, false, $limit);
    }

    /**
     * Keywords whose frequency rose in the last $windowDays vs the prior $windowDays.
     */
    public function getTrendingKeywords(?int $siteId = null, int $windowDays = 7, int $limit = 10): array
    {
        $limit = max(1, min(200, $limit));
        $windowDays = max(1, $windowDays);
        $recentCutoff = $this->cutoff($windowDays);
        $priorCutoff = $this->cutoff($windowDays * 2);

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
     * @return list<array{date: string, searches: int, cost: float, errors: int}>
     */
    public function getDailySeries(int $days = 30, ?string $type = null, ?int $siteId = null): array
    {
        $days = max(1, min(365, $days));

        $q = (new Query())
            ->from(SearchHistoryRecord::tableName())
            ->select(['dateCreated', 'type', 'siteId', 'cost', 'hasError'])
            ->andWhere(['>=', 'dateCreated', $this->cutoff($days)]);

        if ($type !== null) {
            $q->andWhere(['type' => $type]);
        }
        if ($siteId !== null) {
            $q->andWhere(['siteId' => $siteId]);
        }

        $buckets = [];
        foreach ($q->all() as $r) {
            $date = substr((string)$r['dateCreated'], 0, 10);
            $buckets[$date] ??= ['searches' => 0, 'cost' => 0.0, 'errors' => 0];
            $buckets[$date]['searches']++;
            $buckets[$date]['cost'] += (float)$r['cost'];
            if ($r['hasError']) {
                $buckets[$date]['errors']++;
            }
        }

        $series = [];
        $cursor = (new DateTime("-{$days} days"))->setTime(0, 0);
        $end = new DateTime('today');
        while ($cursor <= $end) {
            $key = $cursor->format('Y-m-d');
            $b = $buckets[$key] ?? null;
            $series[] = [
                'date' => $key,
                'searches' => $b['searches'] ?? 0,
                'cost' => round($b['cost'] ?? 0.0, 6),
                'errors' => $b['errors'] ?? 0,
            ];
            $cursor->modify('+1 day');
        }

        return $series;
    }

    /**
     * Embedding cache hit rate over the last $days, 0..1. Returns null when no searches.
     */
    public function getCacheHitRate(int $days = 30): ?float
    {
        return $this->rateOverWindow($days, 'SUM(CASE WHEN embeddingCached = 1 OR embeddingCached = TRUE THEN 1 ELSE 0 END)');
    }

    /**
     * Share of searches in the window that returned zero results, 0..1. Null when no searches.
     */
    public function getZeroResultRate(int $days = 30): ?float
    {
        return $this->rateOverWindow($days, 'SUM(CASE WHEN resultsCount = 0 THEN 1 ELSE 0 END)');
    }

    /**
     * Most recent search errors with the query text and message.
     * Returns rows: [id, type, dateCreated, query, errorMessage].
     */
    public function getRecentErrors(int $limit = 5): array
    {
        $limit = max(1, min(50, $limit));
        return (new Query())
            ->from(SearchHistoryRecord::tableName())
            ->select(['id', 'type', 'dateCreated', 'query', 'errorMessage'])
            ->andWhere(['hasError' => true])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit($limit)
            ->all();
    }

    /**
     * Distinct siteIds that have history rows, hydrated with names for the filter dropdown.
     */
    public function getAvailableSites(): array
    {
        $rows = (new Query())
            ->from(SearchHistoryRecord::tableName())
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
                'name' => $site?->name ?: $site?->handle ?: "Site #{$id}",
                'handle' => $site?->handle,
            ];
        }
        usort($sites, static fn($a, $b) => strcasecmp($a['name'] ?? '', $b['name'] ?? ''));

        return $sites;
    }

    /**
     * Total searches over the given days+site filter, counted on the same
     * non-empty-query universe that getTopKeywords hits are drawn from — so
     * per-query share percentages sum to ~100%.
     */
    public function countSearches(?int $days, ?int $siteId): int
    {
        $q = (new Query())
            ->from(SearchHistoryRecord::tableName())
            ->andWhere(['<>', 'query', '']);

        if ($cutoff = $this->cutoff($days)) {
            $q->andWhere(['>=', 'dateCreated', $cutoff]);
        }
        if ($siteId !== null) {
            $q->andWhere(['siteId' => $siteId]);
        }

        return (int)$q->count('*');
    }

    /**
     * Paginated variant of getTopKeywords (with optional zero-results filter).
     * Returns { items, total, page, perPage, pages }.
     */
    public function paginateKeywords(?int $days, ?int $siteId, bool $zeroOnly, int $page = 1, int $perPage = 25): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $cutoff = $this->cutoff($days);

        $total = $this->groupedCountsTotal($cutoff, null, $siteId, $zeroOnly);
        $items = $this->groupedCounts($cutoff, null, $siteId, $zeroOnly, false, $perPage, ($page - 1) * $perPage);

        return $this->paginated($items, $total, $page, $perPage);
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
        $items = array_slice($all, ($page - 1) * $perPage, $perPage);

        return $this->paginated($items, count($all), $page, $perPage);
    }

    private function groupedCountsTotal(?string $cutoffFrom, ?string $cutoffTo, ?int $siteId, bool $zeroOnly): int
    {
        $sub = $this->groupedQuery($cutoffFrom, $cutoffTo, $siteId, $zeroOnly)
            ->select(['k' => 'LOWER(TRIM([[query]]))']);

        return (int)(new Query())->from(['x' => $sub])->count('*');
    }

    /**
     * Shared GROUP BY helper. Returns rows: [k, query, hits, avgResults, lastSeen].
     */
    private function groupedCounts(
        ?string $cutoffFrom,
        ?string $cutoffTo,
        ?int $siteId,
        bool $zeroOnly,
        bool $minimal = false,
        ?int $limit = null,
        int $offset = 0,
    ): array {
        $select = [
            'k' => 'LOWER(TRIM([[query]]))',
            'query' => 'MIN([[query]])',
            'hits' => 'COUNT(*)',
        ];
        if (!$minimal) {
            $select['avgResults'] = 'AVG([[resultsCount]])';
            $select['lastSeen'] = 'MAX([[dateCreated]])';
        }

        $q = $this->groupedQuery($cutoffFrom, $cutoffTo, $siteId, $zeroOnly)
            ->select($select)
            ->orderBy(['hits' => SORT_DESC]);

        if ($limit !== null) {
            $q->limit($limit);
        }
        if ($offset > 0) {
            $q->offset($offset);
        }

        return $q->all();
    }

    /**
     * Shared base for the keyword GROUP BY queries: the non-empty-query universe,
     * grouped case-insensitively on the trimmed query, with the standard filters.
     */
    private function groupedQuery(?string $cutoffFrom, ?string $cutoffTo, ?int $siteId, bool $zeroOnly): Query
    {
        $q = (new Query())
            ->from(SearchHistoryRecord::tableName())
            ->andWhere(['<>', 'query', ''])
            ->groupBy(['k']);

        if ($cutoffFrom !== null) {
            $q->andWhere(['>=', 'dateCreated', $cutoffFrom]);
        }
        if ($cutoffTo !== null) {
            $q->andWhere(['<', 'dateCreated', $cutoffTo]);
        }
        if ($siteId !== null) {
            $q->andWhere(['siteId' => $siteId]);
        }
        if ($zeroOnly) {
            $q->andWhere(['resultsCount' => 0]);
        }

        return $q;
    }

    /**
     * A windowed COUNT(*) + conditional SUM ratio, 0..1. Returns null when the window is empty.
     */
    private function rateOverWindow(int $days, string $sumExpr): ?float
    {
        $row = (new Query())
            ->from(SearchHistoryRecord::tableName())
            ->andWhere(['>=', 'dateCreated', $this->cutoff($days)])
            ->select(['total' => 'COUNT(*)', 'n' => $sumExpr])
            ->one();

        $total = (int)($row['total'] ?? 0);
        if ($total === 0) {
            return null;
        }

        return round((int)$row['n'] / $total, 4);
    }

    /**
     * Build a DB-ready cutoff timestamp $days in the past, or null when $days is null/≤0.
     */
    private function cutoff(?int $days): ?string
    {
        return ($days !== null && $days > 0)
            ? Db::prepareDateForDb(new DateTime("-{$days} days"))
            : null;
    }

    /**
     * Standard paginated-result envelope shared by the paginate* methods.
     */
    private function paginated(array $items, int $total, int $page, int $perPage): array
    {
        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'pages' => (int)ceil($total / $perPage),
        ];
    }
}

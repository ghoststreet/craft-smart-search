<?php

namespace ghoststreet\craftsmartsearch\services;

use craft\db\Query;
use craft\helpers\Db;
use DateTime;
use ghoststreet\craftsmartsearch\helpers\Logger;
use ghoststreet\craftsmartsearch\models\SearchHistoryEntry;
use ghoststreet\craftsmartsearch\records\SearchHistoryRecord;
use Throwable;
use yii\base\Component;

class HistoryService extends Component
{
    /** The window the trending reports compare against the one before it. */
    public const TRENDING_DAYS = 7;

    /** The trending report ranks at most this many movers. */
    private const TRENDING_CAP = 100;

    /**
     * Insert a search history row. Never throws; failures are logged
     * but never break the search response.
     */
    public function record(SearchHistoryEntry $entry): void
    {
        try {
            $row = new SearchHistoryRecord();
            $row->setAttributes(get_object_vars($entry), false);
            $row->hasError = $entry->errorMessage !== null;
            $row->save(false);
        } catch (Throwable $e) {
            Logger::exception($e, 'history.record');
        }
    }

    /**
     * Aggregate stats for the header.
     */
    public function getStats(int $days): array
    {
        $row = $this->history()
            ->select([
                'embeddingTokens' => 'COALESCE(SUM(embeddingTokens), 0)',
                'llmTokens' => 'COALESCE(SUM(aiAnswerInputTokens + aiAnswerOutputTokens), 0)',
                'avgDuration' => 'AVG(durationMs)',
                'errorCount' => 'SUM(CASE WHEN [[hasError]] THEN 1 ELSE 0 END)',
            ])
            ->andWhere(['>=', 'dateCreated', $this->cutoff($days)])
            ->one();

        return [
            'embeddingTokens' => (int)$row['embeddingTokens'],
            'llmTokens' => (int)$row['llmTokens'],
            'avgDurationMs' => $row['avgDuration'] === null ? null : (int)round((float)$row['avgDuration']),
            'errorCount' => (int)$row['errorCount'],
        ];
    }

    public function paginate(int $page, int $perPage, array $filters): array
    {
        $base = $this->history();
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
                'type', 'query', 'resultsCount', 'embeddingTokens', 'aiAnswerInputTokens', 'aiAnswerOutputTokens',
                'cost', 'durationMs', 'embeddingCached', 'hasError', 'errorMessage', 'dateCreated',
            ])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->all();

        return self::paginated($items, $total, $page, $perPage);
    }

    public function hasAny(): bool
    {
        return $this->history()->exists();
    }

    /**
     * Most-frequent search keywords. Grouped case-insensitively on the trimmed query.
     */
    public function getTopKeywords(?int $days, int $limit): array
    {
        return $this->groupedCounts($this->cutoff($days), null, null, false, false, $limit);
    }

    /**
     * Keywords whose frequency rose in the last TRENDING_DAYS vs the TRENDING_DAYS before.
     */
    public function getTrendingKeywords(?int $siteId, int $limit): array
    {
        $recentCutoff = $this->cutoff(self::TRENDING_DAYS);
        $priorCutoff = $this->cutoff(self::TRENDING_DAYS * 2);

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
            $byKey[$r['k']] ??= [
                'query' => $r['query'],
                'recent' => 0,
                'prior' => 0,
            ];
            $byKey[$r['k']]['prior'] = (int)$r['hits'];
        }

        $rows = [];
        foreach ($byKey as $row) {
            $delta = $row['recent'] - $row['prior'];
            if ($delta <= 0) {
                continue;
            }
            $row['delta'] = $delta;
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
    public function getDailySeries(int $days): array
    {
        $q = $this->history()
            ->select(['dateCreated', 'cost', 'hasError'])
            ->andWhere(['>=', 'dateCreated', $this->cutoff($days)]);

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
     * Most recent search errors with the query text and message.
     * Returns rows: [id, type, dateCreated, query, errorMessage].
     */
    public function getRecentErrors(int $limit): array
    {
        return $this->history()
            ->select(['id', 'type', 'dateCreated', 'query', 'errorMessage'])
            ->andWhere(['hasError' => true])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit($limit)
            ->all();
    }

    /**
     * Total searches over the given days+site filter, counted on the same
     * non-empty-query universe that getTopKeywords hits are drawn from, so
     * per-query share percentages sum to ~100%.
     */
    public function countSearches(?int $days, ?int $siteId): int
    {
        return (int)$this->queriesBetween($this->cutoff($days), null, $siteId)->count('*');
    }

    /**
     * Paginated variant of getTopKeywords (with optional zero-results filter).
     * Returns { items, total, page, perPage, pages }.
     */
    public function paginateKeywords(?int $days, ?int $siteId, bool $zeroOnly, int $page, int $perPage): array
    {
        $cutoff = $this->cutoff($days);

        $total = $this->groupedCountsTotal($cutoff, null, $siteId, $zeroOnly);
        $items = $this->groupedCounts($cutoff, null, $siteId, $zeroOnly, false, $perPage, ($page - 1) * $perPage);

        return self::paginated($items, $total, $page, $perPage);
    }

    /**
     * Paginated variant of getTrendingKeywords. The ranked list is limited to
     * the top TRENDING_CAP movers, then paginated in PHP.
     * Returns { items, total, page, perPage, pages }.
     */
    public function paginateTrending(?int $siteId, int $page, int $perPage): array
    {
        $all = $this->getTrendingKeywords($siteId, self::TRENDING_CAP);
        $items = array_slice($all, ($page - 1) * $perPage, $perPage);

        return self::paginated($items, count($all), $page, $perPage);
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
        $q = $this->queriesBetween($cutoffFrom, $cutoffTo, $siteId)->groupBy(['k']);

        if ($zeroOnly) {
            $q->andWhere(['resultsCount' => 0]);
        }

        return $q;
    }

    /** Rows with a non-empty query, inside the date window and on the site when given. */
    private function queriesBetween(?string $cutoffFrom, ?string $cutoffTo, ?int $siteId): Query
    {
        $q = $this->history()->andWhere(['<>', 'query', '']);

        if ($cutoffFrom !== null) {
            $q->andWhere(['>=', 'dateCreated', $cutoffFrom]);
        }
        if ($cutoffTo !== null) {
            $q->andWhere(['<', 'dateCreated', $cutoffTo]);
        }
        if ($siteId !== null) {
            $q->andWhere(['siteId' => $siteId]);
        }

        return $q;
    }

    private function history(): Query
    {
        return (new Query())->from(SearchHistoryRecord::tableName());
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
     * The paginated-result envelope every paged CP table reads.
     */
    public static function paginated(array $items, int $total, int $page, int $perPage): array
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

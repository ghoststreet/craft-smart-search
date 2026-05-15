<?php

namespace ghoststreet\craftaisearch\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use ghoststreet\craftaisearch\AiSearch;
use ghoststreet\craftaisearch\helpers\Logger;
use ghoststreet\craftaisearch\helpers\PricingTable;
use ghoststreet\craftaisearch\jobs\PruneHistoryJob;
use ghoststreet\craftaisearch\records\SearchHistoryDetailsRecord;
use ghoststreet\craftaisearch\records\SearchHistoryStatsRecord;
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
        $settings = AiSearch::getInstance()->getSettings();
        if (!$settings->historyEnabled) {
            return;
        }

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
            Logger::warning('HistoryService::record failed: ' . $e->getMessage());
            return;
        }

        $this->maybeQueuePrune($settings->historyRetentionDays);
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

    public function findOne(int $id): ?array
    {
        $row = (new Query())
            ->from(['s' => self::STATS_TABLE])
            ->leftJoin(['d' => self::DETAILS_TABLE], '[[d.statsId]] = [[s.id]]')
            ->select([
                's.*',
                'detailsId' => 'd.id',
                'query' => 'd.query',
                'results' => 'd.results',
                'summary' => 'd.summary',
                'confidence' => 'd.confidence',
                'errorMessage' => 'd.errorMessage',
                'detailsDateCreated' => 'd.dateCreated',
            ])
            ->where(['s.id' => $id])
            ->one();

        if (!$row) {
            return null;
        }

        if (!empty($row['results']) && is_string($row['results'])) {
            $decoded = json_decode($row['results'], true);
            $row['results'] = is_array($decoded) ? $decoded : null;
        }

        return $row;
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

    /**
     * Truncate the details table (stats are preserved).
     */
    public function clearAllDetails(): int
    {
        $count = (int)(new Query())->from(self::DETAILS_TABLE)->count('*');
        Craft::$app->getDb()->createCommand()
            ->delete(self::DETAILS_TABLE)
            ->execute();

        Logger::info("HistoryService: cleared {$count} details rows (manual)");

        return $count;
    }

    public function detailsCount(): int
    {
        return (int)(new Query())->from(self::DETAILS_TABLE)->count('*');
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
            Logger::warning('HistoryService: failed to queue prune job: ' . $e->getMessage());
        }
    }
}

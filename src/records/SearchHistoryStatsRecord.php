<?php

namespace ghoststreet\craftaisearch\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $requestId
 * @property string $type
 * @property int|null $userId
 * @property int|null $siteId
 * @property int $resultsCount
 * @property string|null $embeddingModel
 * @property string|null $ragModel
 * @property int $embeddingTokens
 * @property int $ragInputTokens
 * @property int $ragOutputTokens
 * @property int $totalTokens
 * @property string $cost
 * @property int $durationMs
 * @property bool $embeddingCached
 * @property bool $hasError
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class SearchHistoryStatsRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%aisearch_history_stats}}';
    }
}

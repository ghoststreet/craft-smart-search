<?php

namespace ghoststreet\craftaisearch\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int|null $statsId
 * @property string $requestId
 * @property string $query
 * @property array|null $results
 * @property string|null $summary
 * @property string|null $confidence
 * @property string|null $errorMessage
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class SearchHistoryDetailsRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%aisearch_history_details}}';
    }
}

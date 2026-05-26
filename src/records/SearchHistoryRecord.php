<?php

namespace ghoststreet\craftsmartsearch\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $requestId
 * @property string $type
 * @property string $query
 * @property int|null $siteId
 * @property int $resultsCount
 * @property string|null $embeddingModel
 * @property string|null $aiAnswerModel
 * @property int $embeddingTokens
 * @property int $aiAnswerInputTokens
 * @property int $aiAnswerOutputTokens
 * @property int $totalTokens
 * @property string $cost
 * @property int $durationMs
 * @property bool $embeddingCached
 * @property bool $hasError
 * @property string|null $errorMessage
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class SearchHistoryRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%smart_search_history}}';
    }
}

<?php

namespace ghoststreet\craftsmartsearch\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $elementId
 * @property int $siteId
 * @property int|null $userId
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class ExcludedEntryRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%smart_search_excluded_entries}}';
    }
}

<?php

namespace ghoststreet\craftsmartsearch\migrations;

use craft\db\Migration;

/**
 * Install migration for Smart Search plugin.
 *
 * Creates two tables:
 *   - smart_search_history:          one row per search, with all metrics and token/cost data
 *   - smart_search_excluded_entries: entries manually excluded from the search index
 */
class Install extends Migration
{
    public const HISTORY_TABLE = '{{%smart_search_history}}';
    public const EXCLUDED_TABLE = '{{%smart_search_excluded_entries}}';

    public function safeUp(): bool
    {
        if (!$this->db->tableExists(self::HISTORY_TABLE)) {
            $this->createTable(self::HISTORY_TABLE, [
                'id' => $this->primaryKey(),
                'requestId' => $this->string(36)->notNull(),
                'type' => $this->string(16)->notNull(),
                'query' => $this->text()->notNull(),
                'siteId' => $this->integer()->null(),
                'resultsCount' => $this->smallInteger()->notNull()->defaultValue(0),
                'embeddingModel' => $this->string(64)->null(),
                'ragModel' => $this->string(64)->null(),
                'embeddingTokens' => $this->integer()->notNull()->defaultValue(0),
                'ragInputTokens' => $this->integer()->notNull()->defaultValue(0),
                'ragOutputTokens' => $this->integer()->notNull()->defaultValue(0),
                'totalTokens' => $this->integer()->notNull()->defaultValue(0),
                'cost' => $this->decimal(10, 6)->notNull()->defaultValue(0),
                'durationMs' => $this->integer()->notNull()->defaultValue(0),
                'embeddingCached' => $this->boolean()->notNull()->defaultValue(false),
                'hasError' => $this->boolean()->notNull()->defaultValue(false),
                'errorMessage' => $this->text()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, self::HISTORY_TABLE, ['requestId']);
            $this->createIndex(null, self::HISTORY_TABLE, ['type']);
            $this->createIndex(null, self::HISTORY_TABLE, ['dateCreated']);
        }

        if (!$this->db->tableExists(self::EXCLUDED_TABLE)) {
            $this->createTable(self::EXCLUDED_TABLE, [
                'id' => $this->primaryKey(),
                'elementId' => $this->integer()->notNull(),
                'siteId' => $this->integer()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, self::EXCLUDED_TABLE, ['elementId', 'siteId'], true);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::EXCLUDED_TABLE);
        $this->dropTableIfExists(self::HISTORY_TABLE);
        return true;
    }
}

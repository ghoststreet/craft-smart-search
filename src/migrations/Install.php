<?php

namespace ghoststreet\craftsmartsearch\migrations;

use craft\db\Migration;

/**
 * Install migration for Smart Search plugin.
 *
 * Creates three tables:
 *   - aisearch_history_stats:    permanent, never deleted by plugin (token/cost aggregates)
 *   - aisearch_history_details:  deletable on retention prune or manual clear
 *   - aisearch_excluded_entries: entries manually excluded from the search index
 */
class Install extends Migration
{
    public const STATS_TABLE = '{{%aisearch_history_stats}}';
    public const DETAILS_TABLE = '{{%aisearch_history_details}}';
    public const EXCLUDED_TABLE = '{{%aisearch_excluded_entries}}';

    public function safeUp(): bool
    {
        if (!$this->db->tableExists(self::STATS_TABLE)) {
            $this->createTable(self::STATS_TABLE, [
                'id' => $this->primaryKey(),
                'requestId' => $this->string(36)->notNull(),
                'type' => $this->string(16)->notNull(),
                'userId' => $this->integer()->null(),
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
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, self::STATS_TABLE, ['requestId']);
            $this->createIndex(null, self::STATS_TABLE, ['type']);
            $this->createIndex(null, self::STATS_TABLE, ['dateCreated']);
        }

        if (!$this->db->tableExists(self::DETAILS_TABLE)) {
            $this->createTable(self::DETAILS_TABLE, [
                'id' => $this->primaryKey(),
                'statsId' => $this->integer()->null(),
                'requestId' => $this->string(36)->notNull(),
                'query' => $this->text()->notNull(),
                'results' => $this->json()->null(),
                'summary' => $this->text()->null(),
                'confidence' => $this->string(16)->null(),
                'errorMessage' => $this->text()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, self::DETAILS_TABLE, ['statsId']);
            $this->createIndex(null, self::DETAILS_TABLE, ['requestId']);
            $this->createIndex(null, self::DETAILS_TABLE, ['dateCreated']);

            $this->addForeignKey(
                null,
                self::DETAILS_TABLE,
                ['statsId'],
                self::STATS_TABLE,
                ['id'],
                'SET NULL',
                'CASCADE'
            );
        }

        if (!$this->db->tableExists(self::EXCLUDED_TABLE)) {
            $this->createTable(self::EXCLUDED_TABLE, [
                'id' => $this->primaryKey(),
                'elementId' => $this->integer()->notNull(),
                'siteId' => $this->integer()->notNull(),
                'userId' => $this->integer()->null(),
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
        $this->dropTableIfExists(self::DETAILS_TABLE);
        $this->dropTableIfExists(self::STATS_TABLE);
        return true;
    }
}

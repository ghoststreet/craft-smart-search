<?php

namespace ghoststreet\craftsmartsearch\migrations;

use craft\db\Migration;
use craft\helpers\Db;
use ghoststreet\craftsmartsearch\engines\local\LocalSchema;
use ghoststreet\craftsmartsearch\helpers\CacheTag;
use ghoststreet\craftsmartsearch\records\ExcludedEntryRecord;
use ghoststreet\craftsmartsearch\records\SearchHistoryRecord;

/**
 * Creates the tables a fresh install always needs: the shared history and exclusion
 * tables, plus the Standard engine's store, which is the engine every host can run.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createHistoryTable();
        $this->createExcludedTable();
        $this->createIndexTables();

        return true;
    }

    public function safeDown(): bool
    {
        $tables = [...LocalSchema::ALL, ExcludedEntryRecord::tableName(), SearchHistoryRecord::tableName()];

        foreach ($tables as $table) {
            $this->dropTableIfExists($table);
        }

        CacheTag::invalidate();

        return true;
    }

    private function createHistoryTable(): void
    {
        $this->createTable(SearchHistoryRecord::tableName(), [
            'id' => $this->primaryKey(),
            'requestId' => $this->string(36)->null(),
            'type' => $this->string(16)->notNull(),
            'query' => $this->text()->notNull(),
            'siteId' => $this->integer()->null(),
            'resultsCount' => $this->smallInteger()->notNull()->defaultValue(0),
            'embeddingModel' => $this->string(64)->null(),
            'aiAnswerModel' => $this->string(64)->null(),
            'embeddingTokens' => $this->integer()->notNull()->defaultValue(0),
            'aiAnswerInputTokens' => $this->integer()->notNull()->defaultValue(0),
            'aiAnswerOutputTokens' => $this->integer()->notNull()->defaultValue(0),
            'cost' => $this->decimal(10, 6)->notNull()->defaultValue(0),
            'durationMs' => $this->integer()->notNull()->defaultValue(0),
            'embeddingCached' => $this->boolean()->notNull()->defaultValue(false),
            'hasError' => $this->boolean()->notNull()->defaultValue(false),
            'errorMessage' => $this->text()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ], $this->textTableOptions());

        $this->createIndex(null, SearchHistoryRecord::tableName(), ['type']);
        $this->createIndex(null, SearchHistoryRecord::tableName(), ['dateCreated']);
    }

    private function createExcludedTable(): void
    {
        $this->createTable(ExcludedEntryRecord::tableName(), [
            'id' => $this->primaryKey(),
            'elementId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, ExcludedEntryRecord::tableName(), ['elementId', 'siteId'], true);
    }

    /**
     * Tables for the `local` search type: a semantic and keyword index held entirely in
     * Craft's own database, so a site can search without provisioning anything.
     */
    private function createIndexTables(): void
    {
        $textOptions = $this->textTableOptions();

        $this->createTable(LocalSchema::CHUNKS_TABLE, [
            'id' => $this->primaryKey(),
            'elementId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'chunkIndex' => $this->smallInteger()->notNull()->defaultValue(0),
            'totalChunks' => $this->smallInteger()->notNull()->defaultValue(1),
            'sectionId' => $this->integer()->null(),
            'title' => $this->text()->null(),
            'body' => $this->text()->null(),
            'language' => $this->string(32)->notNull(),
            'contentHash' => $this->string(64)->null(),
            'tokenCount' => $this->integer()->notNull()->defaultValue(0),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ], $textOptions);

        $this->createIndex(null, LocalSchema::CHUNKS_TABLE, ['elementId', 'siteId', 'chunkIndex'], true);
        $this->createIndex(null, LocalSchema::CHUNKS_TABLE, ['siteId']);

        $this->createTable(LocalSchema::VECTORS_TABLE, [
            'id' => $this->primaryKey(),
            'elementId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'chunkIndex' => $this->smallInteger()->notNull()->defaultValue(0),
            'sectionId' => $this->integer()->null(),
            'dims' => $this->smallInteger()->notNull(),
            'model' => $this->string(64)->null(),
            'vector' => $this->binary()->notNull(),
        ]);

        $this->createIndex(null, LocalSchema::VECTORS_TABLE, ['elementId', 'siteId', 'chunkIndex'], true);
        $this->createIndex(null, LocalSchema::VECTORS_TABLE, ['siteId', 'id']);

        $this->createTable(LocalSchema::POSTINGS_TABLE, [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->notNull(),
            'elementId' => $this->integer()->notNull(),
            'chunkIndex' => $this->smallInteger()->notNull()->defaultValue(0),
            'term' => $this->string(100)->notNull(),
            'termRaw' => $this->string(100)->notNull(),
            'field' => $this->string(8)->notNull(),
            'tf' => $this->smallInteger()->notNull()->defaultValue(1),
        ], $textOptions);

        $this->createIndex(null, LocalSchema::POSTINGS_TABLE, ['siteId', 'term']);
        $this->createIndex(null, LocalSchema::POSTINGS_TABLE, ['elementId', 'siteId']);

        $this->createTable(LocalSchema::TERMS_TABLE, [
            'siteId' => $this->integer()->notNull(),
            'term' => $this->string(100)->notNull(),
            'termLength' => $this->smallInteger()->notNull(),
            'df' => $this->integer()->notNull()->defaultValue(0),
            'vector' => $this->binary()->null(),
        ], $textOptions);

        $this->addPrimaryKey(null, LocalSchema::TERMS_TABLE, ['siteId', 'term']);
        $this->createIndex(null, LocalSchema::TERMS_TABLE, ['siteId', 'termLength']);

        $this->createTable(LocalSchema::BOOSTS_TABLE, [
            'id' => $this->primaryKey(),
            'elementId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'label' => $this->string(255)->notNull(),
            'terms' => $this->text()->notNull(),
            'weight' => $this->decimal(9, 4)->notNull()->defaultValue(0),
        ], $textOptions);

        $this->createIndex(null, LocalSchema::BOOSTS_TABLE, ['siteId']);
        $this->createIndex(null, LocalSchema::BOOSTS_TABLE, ['elementId', 'siteId']);
    }

    /**
     * Table options for the tables holding entry-derived text.
     *
     * MySQL createTable otherwise inherits the charset from Craft's db config, which
     * resolves to 3-byte `utf8` whenever a non-mb4 collation is configured. A 4-byte
     * character then aborts the insert, so one emoji makes an entry unindexable.
     *
     * Not gated on Craft's `getSupportsMb4()`: that reports what `elements_sites` accepts,
     * which can be 3-byte on a site whose entries do hold 4-byte characters, because Craft
     * stores content as JSON and JSON escapes non-ASCII to ASCII. These tables store the
     * decoded text.
     */
    private function textTableOptions(): ?string
    {
        if (!$this->db->getIsMysql()) {
            return null;
        }

        return 'DEFAULT CHARACTER SET = utf8mb4 DEFAULT COLLATE = ' . Db::defaultCollation($this->db);
    }
}

<?php

namespace ghoststreet\craftsmartsearch\migrations;

use craft\db\Migration;
use craft\helpers\Db;
use ghoststreet\craftsmartsearch\helpers\CacheTag;

class Install extends Migration
{
    public const HISTORY_TABLE = '{{%smart_search_history}}';
    public const EXCLUDED_TABLE = '{{%smart_search_excluded_entries}}';

    public const CHUNKS_TABLE = '{{%smart_search_local_chunks}}';
    public const VECTORS_TABLE = '{{%smart_search_local_vectors}}';
    public const POSTINGS_TABLE = '{{%smart_search_local_postings}}';
    public const TERMS_TABLE = '{{%smart_search_local_terms}}';
    public const BOOSTS_TABLE = '{{%smart_search_local_boosts}}';

    public function safeUp(): bool
    {
        $this->createHistoryTable();
        $this->createExcludedTable();
        $this->createLocalTables();

        return true;
    }

    public function safeDown(): bool
    {
        foreach ([
            self::BOOSTS_TABLE,
            self::TERMS_TABLE,
            self::POSTINGS_TABLE,
            self::VECTORS_TABLE,
            self::CHUNKS_TABLE,
            self::EXCLUDED_TABLE,
            self::HISTORY_TABLE,
        ] as $table) {
            $this->dropTableIfExists($table);
        }

        CacheTag::invalidate();

        return true;
    }

    private function createHistoryTable(): void
    {
        if ($this->db->tableExists(self::HISTORY_TABLE)) {
            return;
        }

        $this->createTable(self::HISTORY_TABLE, [
            'id' => $this->primaryKey(),
            'requestId' => $this->string(36)->notNull(),
            'type' => $this->string(16)->notNull(),
            'query' => $this->text()->notNull(),
            'siteId' => $this->integer()->null(),
            'resultsCount' => $this->smallInteger()->notNull()->defaultValue(0),
            'embeddingModel' => $this->string(64)->null(),
            'aiAnswerModel' => $this->string(64)->null(),
            'embeddingTokens' => $this->integer()->notNull()->defaultValue(0),
            'aiAnswerInputTokens' => $this->integer()->notNull()->defaultValue(0),
            'aiAnswerOutputTokens' => $this->integer()->notNull()->defaultValue(0),
            'totalTokens' => $this->integer()->notNull()->defaultValue(0),
            'cost' => $this->decimal(10, 6)->notNull()->defaultValue(0),
            'durationMs' => $this->integer()->notNull()->defaultValue(0),
            'embeddingCached' => $this->boolean()->notNull()->defaultValue(false),
            'hasError' => $this->boolean()->notNull()->defaultValue(false),
            'errorMessage' => $this->text()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ], $this->textTableOptions());

        $this->createIndex(null, self::HISTORY_TABLE, ['requestId']);
        $this->createIndex(null, self::HISTORY_TABLE, ['type']);
        $this->createIndex(null, self::HISTORY_TABLE, ['dateCreated']);
    }

    private function createExcludedTable(): void
    {
        if ($this->db->tableExists(self::EXCLUDED_TABLE)) {
            return;
        }

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

    /**
     * Tables for the `local` search type: a semantic and keyword index held entirely in
     * Craft's own database, so a site can search without provisioning pgvector Postgres.
     * It shares no storage with the pgvector store, so a fault in one cannot reach the other.
     */
    private function createLocalTables(): void
    {
        $textOptions = $this->textTableOptions();

        if (!$this->db->tableExists(self::CHUNKS_TABLE)) {
            $this->createTable(self::CHUNKS_TABLE, [
                'id' => $this->primaryKey(),
                'elementId' => $this->integer()->notNull(),
                'siteId' => $this->integer()->notNull(),
                'chunkIndex' => $this->smallInteger()->notNull()->defaultValue(0),
                'totalChunks' => $this->smallInteger()->notNull()->defaultValue(1),
                'sectionId' => $this->integer()->null(),
                'title' => $this->text()->null(),
                'body' => $this->text()->null(),
                'language' => $this->string(32)->notNull()->defaultValue('simple'),
                'contentHash' => $this->string(64)->null(),
                /* BM25 length normalisation needs the chunk's own length. */
                'tokenCount' => $this->integer()->notNull()->defaultValue(0),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ], $textOptions);

            $this->createIndex(null, self::CHUNKS_TABLE, ['elementId', 'siteId', 'chunkIndex'], true);
            $this->createIndex(null, self::CHUNKS_TABLE, ['siteId']);
            $this->createIndex(null, self::CHUNKS_TABLE, ['elementId', 'siteId']);
        }

        if (!$this->db->tableExists(self::VECTORS_TABLE)) {
            /*
             * Kept narrow and separate from the chunk text: the scan reads every row for
             * the site, so anything stored here is paid for on every search. sectionId is
             * denormalised in so a section-filtered scan stays single-table.
             */
            $this->createTable(self::VECTORS_TABLE, [
                'id' => $this->primaryKey(),
                'elementId' => $this->integer()->notNull(),
                'siteId' => $this->integer()->notNull(),
                'chunkIndex' => $this->smallInteger()->notNull()->defaultValue(0),
                'sectionId' => $this->integer()->null(),
                'dims' => $this->smallInteger()->notNull(),
                /* Unit-normalised float32, packed little-endian, so cosine is a dot product. */
                'vector' => $this->binary()->notNull(),
            ]);

            $this->createIndex(null, self::VECTORS_TABLE, ['elementId', 'siteId', 'chunkIndex'], true);
            /* The keyset-pagination path: WHERE siteId = ? AND id > ? ORDER BY id. */
            $this->createIndex(null, self::VECTORS_TABLE, ['siteId', 'id']);
        }

        if (!$this->db->tableExists(self::POSTINGS_TABLE)) {
            $this->createTable(self::POSTINGS_TABLE, [
                'id' => $this->primaryKey(),
                'siteId' => $this->integer()->notNull(),
                'elementId' => $this->integer()->notNull(),
                'chunkIndex' => $this->smallInteger()->notNull()->defaultValue(0),
                'term' => $this->string(100)->notNull(),
                'termRaw' => $this->string(100)->notNull(),
                'field' => $this->string(8)->notNull(),
                'tf' => $this->smallInteger()->notNull()->defaultValue(1),
            ], $textOptions);

            $this->createIndex(null, self::POSTINGS_TABLE, ['siteId', 'term']);
            $this->createIndex(null, self::POSTINGS_TABLE, ['elementId', 'siteId']);
        }

        if (!$this->db->tableExists(self::TERMS_TABLE)) {
            /*
             * siteId is in the key, unlike the pgvector dictionary, so idf is computed per
             * site rather than blended across languages. termLength is a stored column
             * rather than a functional index so the length-band lookup the query corrector
             * needs is indexed on MySQL 8.0 and Postgres alike.
             */
            $this->createTable(self::TERMS_TABLE, [
                'siteId' => $this->integer()->notNull(),
                'term' => $this->string(100)->notNull(),
                'termLength' => $this->smallInteger()->notNull(),
                'df' => $this->integer()->notNull()->defaultValue(0),
            ], $textOptions);

            $this->addPrimaryKey(null, self::TERMS_TABLE, ['siteId', 'term']);
            $this->createIndex(null, self::TERMS_TABLE, ['siteId', 'termLength']);
        }

        if (!$this->db->tableExists(self::BOOSTS_TABLE)) {
            $this->createTable(self::BOOSTS_TABLE, [
                'id' => $this->primaryKey(),
                'elementId' => $this->integer()->notNull(),
                'siteId' => $this->integer()->notNull(),
                'label' => $this->string(255)->notNull(),
                /* Phrases already tokenised and stemmed, so match time does no stemming. */
                'terms' => $this->text()->notNull(),
                'weight' => $this->decimal(9, 4)->notNull()->defaultValue(0),
            ], $textOptions);

            $this->createIndex(null, self::BOOSTS_TABLE, ['siteId']);
            $this->createIndex(null, self::BOOSTS_TABLE, ['elementId', 'siteId']);
        }
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

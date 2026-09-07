<?php

namespace ghoststreet\craftsmartsearch\migrations;

use craft\db\Migration;

/**
 * Tables for the `local` search type: a semantic and keyword index held entirely in
 * Craft's own database, so a site can search without provisioning pgvector Postgres.
 *
 * Deliberately separate from the pgvector store rather than mirroring it. The two
 * implementations share no storage, so a fault in one cannot reach the other.
 */
class m260905_000000_local_index extends Migration
{
    public const CHUNKS_TABLE = '{{%smart_search_local_chunks}}';
    public const VECTORS_TABLE = '{{%smart_search_local_vectors}}';
    public const POSTINGS_TABLE = '{{%smart_search_local_postings}}';
    public const TERMS_TABLE = '{{%smart_search_local_terms}}';
    public const BOOSTS_TABLE = '{{%smart_search_local_boosts}}';

    public function safeUp(): bool
    {
        self::createTables($this);
        return true;
    }

    public function safeDown(): bool
    {
        self::dropTables($this);
        return true;
    }

    /**
     * Shared with Install so a fresh install and an upgrade cannot drift apart.
     */
    public static function createTables(Migration $m): void
    {
        if (!$m->db->tableExists(self::CHUNKS_TABLE)) {
            $m->createTable(self::CHUNKS_TABLE, [
                'id' => $m->primaryKey(),
                'elementId' => $m->integer()->notNull(),
                'siteId' => $m->integer()->notNull(),
                'chunkIndex' => $m->smallInteger()->notNull()->defaultValue(0),
                'totalChunks' => $m->smallInteger()->notNull()->defaultValue(1),
                'sectionId' => $m->integer()->null(),
                'title' => $m->text()->null(),
                'body' => $m->text()->null(),
                'language' => $m->string(32)->notNull()->defaultValue('simple'),
                'contentHash' => $m->string(64)->null(),
                /* BM25 length normalisation needs the chunk's own length. */
                'tokenCount' => $m->integer()->notNull()->defaultValue(0),
                'dateCreated' => $m->dateTime()->notNull(),
                'dateUpdated' => $m->dateTime()->notNull(),
                'uid' => $m->uid(),
            ]);

            $m->createIndex(null, self::CHUNKS_TABLE, ['elementId', 'siteId', 'chunkIndex'], true);
            $m->createIndex(null, self::CHUNKS_TABLE, ['siteId']);
            $m->createIndex(null, self::CHUNKS_TABLE, ['elementId', 'siteId']);
        }

        if (!$m->db->tableExists(self::VECTORS_TABLE)) {
            /*
             * Kept narrow and separate from the chunk text on purpose: the scan reads
             * every row for the site, so anything stored here is paid for on every
             * search. sectionId is denormalised in so a section-filtered scan stays
             * single-table — 4 bytes against a 2KB row.
             */
            $m->createTable(self::VECTORS_TABLE, [
                'id' => $m->primaryKey(),
                'elementId' => $m->integer()->notNull(),
                'siteId' => $m->integer()->notNull(),
                'chunkIndex' => $m->smallInteger()->notNull()->defaultValue(0),
                'sectionId' => $m->integer()->null(),
                'dims' => $m->smallInteger()->notNull(),
                /* Unit-normalised float32, packed little-endian, so cosine is a dot product. */
                'vector' => $m->binary()->notNull(),
            ]);

            $m->createIndex(null, self::VECTORS_TABLE, ['elementId', 'siteId', 'chunkIndex'], true);
            /* The keyset-pagination path: WHERE siteId = ? AND id > ? ORDER BY id. */
            $m->createIndex(null, self::VECTORS_TABLE, ['siteId', 'id']);
        }

        if (!$m->db->tableExists(self::POSTINGS_TABLE)) {
            $m->createTable(self::POSTINGS_TABLE, [
                'id' => $m->primaryKey(),
                'siteId' => $m->integer()->notNull(),
                'elementId' => $m->integer()->notNull(),
                'chunkIndex' => $m->smallInteger()->notNull()->defaultValue(0),
                'term' => $m->string(100)->notNull(),
                'termRaw' => $m->string(100)->notNull(),
                'field' => $m->string(8)->notNull(),
                'tf' => $m->smallInteger()->notNull()->defaultValue(1),
            ]);

            $m->createIndex(null, self::POSTINGS_TABLE, ['siteId', 'term']);
            $m->createIndex(null, self::POSTINGS_TABLE, ['elementId', 'siteId']);
        }

        if (!$m->db->tableExists(self::TERMS_TABLE)) {
            /*
             * siteId is in the key, unlike the pgvector dictionary, so idf is computed
             * per site rather than blended across languages. termLength is a stored
             * column rather than a functional index so the corrector's length-band
             * lookup is indexed on MySQL 8.0 and Postgres alike.
             */
            $m->createTable(self::TERMS_TABLE, [
                'siteId' => $m->integer()->notNull(),
                'term' => $m->string(100)->notNull(),
                'termLength' => $m->smallInteger()->notNull(),
                'df' => $m->integer()->notNull()->defaultValue(0),
            ]);

            $m->addPrimaryKey(null, self::TERMS_TABLE, ['siteId', 'term']);
            $m->createIndex(null, self::TERMS_TABLE, ['siteId', 'termLength']);
        }

        if (!$m->db->tableExists(self::BOOSTS_TABLE)) {
            $m->createTable(self::BOOSTS_TABLE, [
                'id' => $m->primaryKey(),
                'elementId' => $m->integer()->notNull(),
                'siteId' => $m->integer()->notNull(),
                /* Display only, e.g. "2 bedroom + 1 bathroom". */
                'label' => $m->string(255)->notNull(),
                /* Phrases already tokenised and stemmed, so match time does no stemming. */
                'terms' => $m->text()->notNull(),
                'weight' => $m->decimal(9, 4)->notNull()->defaultValue(0),
            ]);

            $m->createIndex(null, self::BOOSTS_TABLE, ['siteId']);
            $m->createIndex(null, self::BOOSTS_TABLE, ['elementId', 'siteId']);
        }
    }

    public static function dropTables(Migration $m): void
    {
        $m->dropTableIfExists(self::BOOSTS_TABLE);
        $m->dropTableIfExists(self::TERMS_TABLE);
        $m->dropTableIfExists(self::POSTINGS_TABLE);
        $m->dropTableIfExists(self::VECTORS_TABLE);
        $m->dropTableIfExists(self::CHUNKS_TABLE);
    }
}

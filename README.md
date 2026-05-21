# Smart Search for Craft CMS


**AI-powered semantic, hybrid, and RAG search for Craft CMS.**

---

> **Important: PostgreSQL with pgvector is REQUIRED**
>
> This plugin requires PostgreSQL with the [pgvector](https://github.com/pgvector/pgvector) extension installed. **MySQL and SQLite are NOT supported.** This is a hard requirement due to the vector similarity search capabilities that power semantic search.

---

## Overview

Smart Search brings intelligent, AI-powered search capabilities to your Craft CMS site. Instead of relying solely on keyword matching, Smart Search understands the *meaning* behind queries and content, delivering more relevant results.

**Four search types included:**

- **Semantic Search** - Find content by meaning using OpenAI embeddings and pgvector similarity search
- **BM25 Full-Text Search** - Traditional keyword search using PostgreSQL's native full-text ranking
- **Hybrid Search (RRF)** - Best of both worlds with Reciprocal Rank Fusion algorithm combining semantic and keyword signals
- **RAG Search** - AI-generated summaries with source citations using Retrieval Augmented Generation

## Key Features

- **Automatic indexing** - Entries are indexed automatically when saved
- **Smart semantic chunking** - Long content is intelligently split for optimal embedding quality
- **All field types supported** - Works with plain text, CKEditor, Matrix, Super Table, and nested fields
- **Multi-level caching** - Request-level and persistent caching (7-day TTL) for embeddings
- **Configurable algorithms** - Fine-tune similarity thresholds, RRF weights, and ranking parameters
- **Console commands** - Bulk index your entire site from the command line
- **RESTful API** - Three API endpoints for different search needs
- **Control Panel dashboard** - View indexing statistics and manage your search index
- **Multi-site support** - Filter search results by site

## Requirements

| Requirement | Version |
|-------------|---------|
| Craft CMS | 4.0+ or 5.0+ |
| PHP | 8.2+ |
| PostgreSQL | 12+ with [pgvector](https://github.com/pgvector/pgvector) extension |
| OpenAI API | Valid API key ([pricing](https://openai.com/pricing)) |

> **Note:** This plugin requires an OpenAI API key. OpenAI API usage incurs separate costs based on your usage volume.

### PostgreSQL & pgvector Setup

The pgvector extension must be installed on your PostgreSQL server. See the [pgvector installation guide](https://github.com/pgvector/pgvector#installation) for instructions.

Most managed PostgreSQL providers (Neon, Supabase, Railway, Render, AWS RDS) offer pgvector as a one-click extension.

## Database Setup (Required, Admin-Owned)

**The plugin does not create or modify the database schema.** You — the admin — own the vectors table, its indexes, the runtime role, and any RLS policies. The plugin only reads from and writes rows to a table that already exists. If the table is missing, the plugin fails fast with a clear error rather than attempting any DDL.

This boundary is intentional: it keeps the plugin's database role to the minimum privilege it actually needs (no `CREATE`, no `DROP`, no extension management) and prevents an attacker who compromises the Craft application from issuing DDL against your Postgres host.

### One-time setup

Run the following as the **project owner / superuser role** (e.g. the `postgres` role on Supabase). You only do this once per project.

```sql
-- 1. Enable pgvector
CREATE EXTENSION IF NOT EXISTS vector;

-- 2. Create the vectors table.
--    You may rename it; whatever name you choose goes into the plugin's
--    `Vectors Table Name` setting. Identifiers must match /^[a-zA-Z_][a-zA-Z0-9_]{0,62}$/.
CREATE TABLE IF NOT EXISTS aisearch_vectors (
    id            bigserial PRIMARY KEY,
    "elementId"   integer NOT NULL,
    "siteId"      integer NOT NULL,
    "chunkIndex"  integer NOT NULL DEFAULT 0,
    "totalChunks" integer NOT NULL DEFAULT 1,
    vector        vector(1536) NOT NULL,
    content       text,
    "contentHash" char(64),
    "dateCreated" timestamptz NOT NULL DEFAULT now(),
    "dateUpdated" timestamptz NOT NULL DEFAULT now(),
    UNIQUE ("elementId", "siteId", "chunkIndex")
);

CREATE INDEX IF NOT EXISTS aisearch_vectors_element_idx      ON aisearch_vectors ("elementId");
CREATE INDEX IF NOT EXISTS aisearch_vectors_site_idx         ON aisearch_vectors ("siteId");
CREATE INDEX IF NOT EXISTS aisearch_vectors_chunk_idx        ON aisearch_vectors ("chunkIndex");
CREATE INDEX IF NOT EXISTS aisearch_vectors_element_site_idx ON aisearch_vectors ("elementId", "siteId");
CREATE INDEX IF NOT EXISTS aisearch_vectors_content_gin ON aisearch_vectors
    USING gin (to_tsvector('simple', COALESCE(content, '')));
CREATE INDEX IF NOT EXISTS aisearch_vectors_hnsw_cos    ON aisearch_vectors
    USING hnsw (vector vector_cosine_ops) WITH (m = 16, ef_construction = 64);

-- 3. Create a dedicated, least-privilege role for the plugin's runtime connection.
--    DO NOT reuse your project owner / Supabase service-role credentials in the
--    plugin settings. Use this role and this role only.
CREATE ROLE craft_aisearch LOGIN PASSWORD 'replace-with-a-strong-password';
GRANT USAGE ON SCHEMA public TO craft_aisearch;
GRANT SELECT, INSERT, UPDATE, DELETE ON aisearch_vectors TO craft_aisearch;
GRANT USAGE, SELECT ON SEQUENCE aisearch_vectors_id_seq TO craft_aisearch;

-- 4. (Recommended) Enable Row-Level Security scoped by siteId.
--    The plugin sets `app.site_id` per request, so this policy filters rows
--    to the current site even if the runtime credential is exposed.
ALTER TABLE aisearch_vectors ENABLE ROW LEVEL SECURITY;

CREATE POLICY aisearch_vectors_site_scope ON aisearch_vectors
    USING (
        "siteId" = COALESCE(NULLIF(current_setting('app.site_id', true), '')::int, "siteId")
    )
    WITH CHECK (
        "siteId" = COALESCE(NULLIF(current_setting('app.site_id', true), '')::int, "siteId")
    );

-- 5. Allow the runtime role to set the GUC used by the policy.
GRANT SET ON PARAMETER app.site_id TO craft_aisearch;
```

### Plugin configuration

In **Smart Search → Settings → Database**:

| Setting | Value |
|---------|-------|
| Host | Your Postgres host |
| Database | Your database name (`postgres` on Supabase) |
| User | `craft_aisearch` (the role you just created — never the project owner) |
| Password | An env reference like `$CRAFT_AISEARCH_DB_PASSWORD` (plain text is rejected) |
| SSL Mode | `require` minimum for any non-localhost host (`disable`/`allow`/`prefer` are rejected) |
| Vectors Schema Name | `public` (or your custom schema) |
| Vectors Table Name | `aisearch_vectors` (or whatever name you used in step 2) |

The plugin validates schema/table names against `/^[a-zA-Z_][a-zA-Z0-9_]{0,62}$/` at save time — strings containing spaces, quotes, or punctuation are rejected outright. Identifiers are still interpolated into SQL, so the allowlist is the only safeguard; do not bypass it.

### Vector dimensions

The example schema uses `vector(1536)` to match the default embedding model. If you change the **Vector Dimensions** setting, the existing column type must match — alter the table (or recreate it and re-index) before changing the setting:

```sql
ALTER TABLE aisearch_vectors ALTER COLUMN vector TYPE vector(3072);
```

### Removing the plugin

Uninstalling the plugin **does not** drop your vectors table — that data belongs to you. Drop it explicitly when you no longer want it:

```sql
DROP TABLE IF EXISTS aisearch_vectors;
DROP ROLE  IF EXISTS craft_aisearch;
```

## Installation

### Via Plugin Store (Recommended)

1. Go to the Plugin Store in your project's Control Panel
2. Search for "Smart Search"
3. Click "Install"

### Via Composer

```bash
# Navigate to your Craft project
cd /path/to/my-project

# Install the plugin
composer require ghoststreet/craft-smart-search

# Install in Craft
./craft plugin/install smart-search
```

### Getting Started

1. Provision PostgreSQL and run the [Database Setup](#database-setup-required-admin-owned) SQL **before** enabling the plugin — the plugin will refuse to operate against a missing table.
2. Navigate to **Smart Search** in the Control Panel sidebar.
3. In **Settings → Quick start**, set your OpenAI API key (as a `$ENV_VAR` reference — plain text is rejected) and the daily cost cap.
4. In **Settings → Advanced → Database**, fill in the connection fields using the dedicated `craft_aisearch` role, and set the **Vectors Table Name** to whatever name you used in the schema SQL.
5. Go to **Index → Overview & sync** and click **Sync entire index** to build your initial index.
6. Test your search from the same site (CSRF is enforced — see [Security model](#security-model) below).

## Control Panel layout

The plugin exposes four sections under **Smart Search**:

- **Dashboard** — Health score, KPI cards (searches, cost, coverage, response time) with 30-day Chart.js sparklines, daily budget gauge, top queries / zero-result queries, recent errors, and proactive recommendations (budget warnings, stale-index advisories, cache-hit hints, error-rate spikes).
- **Insights** — Tabs for *Top queries*, *Zero results*, *Trending* (7-day delta), and *History log* (paginated, filterable). Click any history row for token / cost / result breakdown.
- **Index** — Tabs for *Overview & sync* (run a full reindex, watch live progress), *Entries* (per-entry indexed/stale/not-indexed status with section + site filters), and *Per-site coverage*.
- **Settings** — Three tabs: *Quick start*, *Search behavior*, *Advanced*. Less-used fields are progressively disclosed inside `<details>` blocks.

## Search Types Explained

### Semantic Search

Semantic search uses OpenAI embeddings to understand the meaning of both your query and your content. Instead of matching keywords, it finds content that is conceptually similar to what you're asking for.

**Supported embedding models:**
- `text-embedding-3-small` (default) - Fast and cost-effective
- `text-embedding-3-large` - Higher accuracy

**Best for:** Natural language queries, conceptual searches, "find content like this"

### BM25 Full-Text Search

BM25 (Best Matching 25) is a traditional keyword-based ranking algorithm using PostgreSQL's native full-text search with `ts_rank_cd`. It excels at finding exact term matches.

**Best for:** Known keywords, exact phrases, technical terms

### Hybrid Search (RRF)

Hybrid search combines semantic and BM25 results using the Reciprocal Rank Fusion algorithm. This gives you the best of both worlds - conceptual understanding plus keyword precision.

**How it works:**
1. Query is sent to both semantic and BM25 search
2. Results are ranked using RRF formula: `score = weight / (k + rank)`
3. Scores are combined with configurable weights (default: 30% semantic, 70% BM25)
4. Single-signal penalty applied to results appearing in only one search type

**Best for:** General-purpose search, production use cases

### RAG Search

RAG search performs hybrid search first, then passes the top results to an OpenAI language model to generate a contextual summary with source citations.

**Supported LLM models:**
- `gpt-5-nano` (default) - Fast and efficient
- `gpt-4o-mini` - Balanced performance
- `gpt-4o` - Highest quality
- `gpt-4-turbo` - High quality with larger context
- `gpt-3.5-turbo` - Legacy option

**Best for:** Question answering, summarization, chatbot integrations

## Security model

The public search API is built for visitor traffic on the same Craft site that hosts the plugin. Three layers gate every request:

1. **CSRF token (always required, no exceptions).** Browser requests send `X-CSRF-Token`; the SSE stream sends the token as a query parameter (because `EventSource` cannot set headers). Requests without a valid Craft CSRF token are rejected with `400`.
2. **Origin/Referer allowlist.** If the browser sends an `Origin` or `Referer` header, it must match the site host or an entry in the `Allowed Origins` setting. Cross-origin embeds are rejected with `403`.
3. **Bearer token (optional, additive).** If `apiToken` is set in plugin settings, every request must additionally present `Authorization: Bearer <token>` validated with `hash_equals`. This is the second factor for headless callers and never replaces CSRF.

On top of authentication, every request passes through `RateLimitService`, which enforces:

- Per-IP token buckets (per-minute + per-hour) — tighter for `rag-search` than for cheaper searches.
- Per-IP and global concurrency caps on RAG (in-flight requests).
- Per-IP and global daily cost ceilings in USD; when exhausted, RAG returns `503` with `Retry-After`.

User input is normalized to NFC, stripped of ASCII control characters and OpenAI chat-template control sequences (`<|...|>`), and capped at **150 characters** before it ever reaches the embeddings or LLM endpoints. The LLM system prompt wraps the visitor's question in `<user_query>...</user_query>` and instructs the model to treat its contents as data — the standard structural defense against prompt injection. The assembled RAG context is additionally capped by a token budget (`maxPromptTokens`) so a large result set cannot blow up the prompt size.

API keys are stored as environment-variable references (`$OPENAI_API_KEY`, `$CRAFT_AISEARCH_DB_PASSWORD`) — plain-text secrets are rejected at save time. Stack traces in error responses are gated behind the `Expose Stack Traces` setting (off by default), not by `devMode`.

## API Reference

All endpoints require a valid Craft CSRF token and accept the following common parameters:

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `q` | string | Yes | - | The search query (max 150 chars; ASCII control + chat-template sequences are stripped before use) |
| `limit` | integer | No | 10 (5 for RAG) | Maximum results to return |
| `siteId` | integer | No | Current site | Filter results by site ID |

`GET /api/smart-search/hybrid`, `GET /api/smart-search`, and `GET /api/smart-search/rag/stream` (SSE) handle browser flow; `POST /api/smart-search/rag` is required for the non-streaming RAG endpoint.

### GET /api/smart-search/hybrid

Performs hybrid search combining semantic and BM25 signals.

**Response:**
```json
{
  "success": true,
  "query": "your search query",
  "hybrid": true,
  "semanticResults": [
    {
      "id": 123,
      "title": "Entry Title",
      "url": "https://site.com/entry-slug",
      "excerpt": "Relevant content excerpt...",
      "score": 0.85,
      "similarity": 0.92
    }
  ],
  "semanticCount": 5
}
```

### GET /api/smart-search

Performs native Craft CMS search (keyword-based).

**Response:**
```json
{
  "success": true,
  "query": "your search query",
  "results": [
    {
      "id": 123,
      "title": "Entry Title",
      "url": "https://site.com/entry-slug"
    }
  ],
  "count": 5
}
```

### GET /api/smart-search/rag

Performs AI-powered search with generated summary.

**Response:**
```json
{
  "success": true,
  "query": "your search query",
  "summary": "Based on your content, here is what I found...",
  "sources": [
    {
      "id": 123,
      "title": "Entry Title",
      "url": "https://site.com/entry-slug"
    }
  ],
  "count": 3,
  "confidence": 0.87
}
```

## Console Commands

### Index All Entries

```bash
./craft smart-search/index
```

### Index Specific Section

```bash
./craft smart-search/index --section=blog
```

### Index Specific Site

```bash
./craft smart-search/index --siteId=1
```

### Combined Options

```bash
./craft smart-search/index --section=news --siteId=2
```

The index command will:
1. Initialize the database schema if needed
2. Wipe existing vectors (full re-index)
3. Process all matching entries in batches
4. Report progress and any failures

## Configuration

All settings are configurable via the Control Panel under **Smart Search**.

### Database Settings

| Setting | Default | Description |
|---------|---------|-------------|
| PostgreSQL Host | - | Database host (supports connection URIs) |
| PostgreSQL Port | `5432` | Database port |
| PostgreSQL Database | - | Database name |
| PostgreSQL User | - | Database username |
| PostgreSQL Password | - | Database password |
| SSL Mode | `require` | SSL connection mode (disable, allow, prefer, require, verify-ca, verify-full) |

All database settings support environment variables using Craft's `$VARIABLE_NAME` syntax.

### Embedding Settings

| Setting | Default | Description |
|---------|---------|-------------|
| Hybrid Embedding Model | `text-embedding-3-small` | Model for hybrid search embeddings |
| RAG Embedding Model | `text-embedding-3-small` | Model for RAG search embeddings |
| Cache TTL | `604800` (7 days) | Embedding cache duration in seconds |

### Hybrid Search Settings

| Setting | Default | Range | Description |
|---------|---------|-------|-------------|
| Minimum Similarity Threshold | `0.90` | 0-1 | Minimum cosine similarity for results |
| RRF K Parameter | `60` | 1-1000 | Reciprocal Rank Fusion constant |
| Semantic Weight | `0.3` | 0-1 | Weight for semantic results |
| BM25 Weight | `0.7` | 0-1 | Weight for BM25 results |
| Min Semantic Threshold | `0.5` | 0-1 | Minimum score for semantic-only results |
| Single Signal Penalty | `0.5` | 0-1 | Penalty for results from only one search type |
| Max Semantic Results | `100` | 10-500 | Maximum semantic candidates to consider |

### RAG Search Settings

| Setting | Default | Range | Description |
|---------|---------|-------|-------------|
| LLM Model | `gpt-5-nano` | - | OpenAI model for summaries |
| Temperature | `0.3` | 0-2 | Response randomness (lower = more focused) |
| Custom Prompt | - | - | Custom system prompt for the AI |

### Content Chunking Settings

| Setting | Default | Range | Description |
|---------|---------|-------|-------------|
| Min Chunk Tokens | `100` | 10-500 | Minimum tokens per chunk |
| Target Chunk Tokens | `400` | 100-1000 | Ideal chunk size |
| Max Chunk Tokens | `600` | 200-2000 | Maximum tokens per chunk |
| Overlap Tokens | `40` | 0-200 | Token overlap between chunks |
| Chunk Threshold | `500` | 100-1000 | Content size before chunking |

### Advanced Settings

| Setting | Default | Range | Description |
|---------|---------|-------|-------------|
| IVFFlat Lists | `100` | 10-1000 | PostgreSQL index parameter |
| Excerpt Length | `200` | 50-500 | Characters shown in excerpts |
| Short Query Threshold | `3` | 1-10 | Word count for short query handling |

## How Content is Indexed

### Automatic Indexing

Entries are automatically indexed when:
- The entry is saved (not a draft or revision)
- The entry has a URL (entries without URLs are skipped)
- The entry is enabled/live

### What Gets Indexed

- **Entry title** (always)
- **All text-based custom fields:**
  - Plain Text, CKEditor/Redactor
  - Matrix fields (all blocks and nested fields)
  - Super Table fields
  - Other nested field types

### Content Chunking

Long content is automatically split into semantic chunks:
1. Content exceeding the threshold (default: 500 tokens) is chunked
2. Chunks are split at natural boundaries (paragraphs, sentences)
3. Overlap between chunks maintains context continuity
4. Each chunk is embedded separately

### Multi-Site

Each site's content is indexed with its `siteId`, allowing site-specific searches.

## Troubleshooting

### "pgvector extension not found"

Ensure pgvector is installed on your PostgreSQL server:

```sql
CREATE EXTENSION IF NOT EXISTS vector;
```

On managed providers, enable it through their dashboard.

### "Connection refused" or database errors

1. Verify PostgreSQL host, port, and credentials
2. Check that the `craft_aisearch` role has `SELECT, INSERT, UPDATE, DELETE` on the vectors table and `USAGE` on the schema — the plugin needs nothing more
3. For remote databases, ensure your IP is allowlisted
4. SSL must be `require` (or stricter) for any non-localhost host; the plugin will refuse weaker modes

### No results returned

1. Check that entries have been indexed (**Smart Search > Dashboard**)
2. Verify entries have URLs (entries without URLs are not indexed)
3. Run a manual re-index via **Data Sync** or console command
4. Lower the similarity threshold in Hybrid Search settings

### Rate limiting from OpenAI

The plugin caches embeddings for 7 days by default. If you're hitting rate limits:
1. Increase the cache TTL in settings
2. Index content in smaller batches
3. Consider upgrading your OpenAI API tier

### Performance tuning

For large sites (10,000+ entries):
1. Increase `IVFFlat Lists` setting (higher = more accurate, slower index build)
2. Run initial indexing during off-peak hours
3. Consider dedicated PostgreSQL hosting

## Support

- **Email:** dev@ghost.st
- **Issues:** Report bugs via email

## License

This plugin is licensed under the [Craft License](https://craftcms.github.io/license/). A license is required for each Craft project running Smart Search in production.

---

Built by [Ghost Street](https://ghost.st)

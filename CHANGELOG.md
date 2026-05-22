# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## 0.7.0 - 2025-01-29

### Added
- API endpoint authentication with configurable token (Bearer or query param)
- Test Connection and Test API Key buttons in settings
- Progress feedback during Data Sync reindex with live polling
- Twig template variables (`craft.smartSearch.search()`, `craft.smartSearch.rag()`)
- Section/entry-type filtering for selective indexing
- Configurable vector dimensions (512, 1024, 1536, 3072)
- Uninstall cleanup for external PostgreSQL tables

### Changed
- CLI reindex is now incremental by default; use `--wipe` flag for destructive reindex
- Clarified threshold labels in settings (Final Result Threshold, Semantic Pre-filter Threshold)
- Settings validation errors now display inline on form fields

## 0.6.0 - 2025-01-28

### Added
- Five-tab settings interface for API, database, hybrid search, RAG, and advanced configuration
- Support for environment variables in all sensitive settings
- Custom log target (`smart-search.log`) for isolated debugging

### Changed
- Improved settings organization and help text

## 0.5.0 - 2025-01-27

### Added
- RAG (Retrieval-Augmented Generation) search with AI-generated summaries and source attribution
- RAG API endpoint (`/api/smart-search/rag`)

## 0.4.0 - 2025-01-26

### Added
- Hybrid search combining BM25 keyword scoring with semantic similarity via Reciprocal Rank Fusion
- Hybrid search API endpoint (`/api/smart-search/hybrid`)
- Request-level and persistent embedding cache to minimize API costs

## 0.3.0 - 2025-01-24

### Added
- Data Sync page with wipe-and-reindex functionality
- CLI bulk indexing command (`php craft smart-search/index`) with site and section filters
- Control panel dashboard with index statistics and connection status

## 0.2.0 - 2025-01-22

### Added
- Content chunking with configurable sizes and overlap for optimal embedding quality
- Automatic content indexing on entry save and deletion via queue jobs

## 0.1.0 - 2025-01-20

### Added
- Initial plugin scaffold
- Semantic vector search using OpenAI embeddings and PostgreSQL pgvector
- Craft search API endpoint (`/api/smart-search`)

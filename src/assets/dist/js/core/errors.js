(function () {
    'use strict';
    var ns = window.SmartSearch;

    var MESSAGES = {
        SEARCH_SEMANTIC_FAILED:      'Semantic search failed. Please try again.',
        SEARCH_RAG_FAILED:           'AI summary failed. Please try again.',
        SEARCH_VECTOR_QUERY_FAILED:  'Vector similarity search failed.',
        SEARCH_ENTRY_NOT_FOUND:      'The requested entry could not be found.',
        SEARCH_VALIDATION_FAILED:    'Your search request was invalid.',
        EMBEDDING_EMPTY_TEXT:        'Cannot generate an embedding for empty text.',
        EMBEDDING_RATE_LIMITED:      'OpenAI rate limit reached. Please retry shortly.',
        EMBEDDING_QUOTA_EXCEEDED:    'OpenAI quota exceeded. Check your OpenAI account billing.',
        EMBEDDING_INVALID_API_KEY:   'OpenAI rejected the request: the API key is invalid.',
        EMBEDDING_API_ERROR:         'OpenAI embedding request failed.',
        DATABASE_QUERY_FAILED:       'A database query failed.',
        DATABASE_TABLE_MISSING:      'The vector table does not exist yet. Set up the pgvector schema before indexing.',
        DATABASE_CONFIG_INCOMPLETE:  'Database connection is not configured.',
        DATABASE_CONNECTION_ERROR:   'Could not connect to the vector database.',
        RATE_LIMIT_REQUESTS:         'Too many requests. Slow down and retry shortly.',
        RATE_LIMIT_CONCURRENCY:      'Too many concurrent requests. Try again in a moment.',
        CONFIG_MISSING_API_KEY:      'An API key is not configured. Please set it in plugin settings.',
        UNKNOWN:                     'Something went wrong. The administrator can find details in the Smart Search log.'
    };

    ns.core.errors = {
        messageFor: function (err) {
            var msg = (err && err.message) || MESSAGES[(err && err.code) || 'UNKNOWN'];
            if (err && err.requestId) msg += ' (id: ' + err.requestId + ')';
            return msg;
        }
    };
})();

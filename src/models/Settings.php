<?php

namespace ghoststreet\craftaisearch\models;

use craft\base\Model;
use craft\helpers\App;

/**
 * ai-search settings
 */
class Settings extends Model
{
    public ?string $openaiApiKey = null;
    public ?string $apiToken = null;

    public string $hybridEmbeddingModel = 'text-embedding-3-small';
    public string $ragEmbeddingModel = 'text-embedding-3-small';

    public ?string $postgresqlHost = null;
    public string|int $postgresqlPort = 5432;
    public ?string $postgresqlDatabase = null;
    public ?string $postgresqlUser = null;
    public ?string $postgresqlPassword = null;
    public string $postgresqlSslMode = 'require';

    public float $minimumSimilarityThreshold = 0.90;
    public int $rrfK = 60;
    public float $rrfSemanticWeight = 0.3;
    public float $rrfBm25Weight = 0.7;

    public string $ragModel = 'gpt-5.4-nano';
    public ?string $ragCustomPrompt = null;

    public int $minChunkTokens = 100;
    public int $targetChunkTokens = 400;
    public int $maxChunkTokens = 600;
    public int $overlapTokens = 40;
    public int $chunkThresholdTokens = 500;

    public int $embeddingCacheTtl = 604800;

    public float $minSemanticThreshold = 0.20;
    public float $singleSignalPenalty = 0.5;
    public int $maxSemanticResults = 100;

    public int $excerptLength = 200;

    public array $indexableSections = [];

    public int $vectorDimensions = 1536;

    public bool $historyEnabled = true;
    public int $historyRetentionDays = 30;

    /**
     * Validation rules for all plugin settings, grouped by feature area.
     */
    public function rules(): array
    {
        return [
            // OpenAI API validation
            [['openaiApiKey'], 'required'],

            // API token validation
            [['apiToken'], 'string'],

            // Embedding model validation
            [['hybridEmbeddingModel', 'ragEmbeddingModel'], 'required'],
            [['hybridEmbeddingModel', 'ragEmbeddingModel'], 'string'],
            [['hybridEmbeddingModel', 'ragEmbeddingModel'], 'in', 'range' => ['text-embedding-3-small', 'text-embedding-3-large']],

            // PostgreSQL validation
            [['postgresqlHost', 'postgresqlDatabase', 'postgresqlUser', 'postgresqlPassword', 'postgresqlSslMode', 'postgresqlPort'], 'string'],
            [['postgresqlPort'], 'default', 'value' => 5432],
            [['postgresqlSslMode'], 'required'],
            [['postgresqlSslMode'], 'in', 'range' => ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full']],

            // Hybrid search validation
            [['minimumSimilarityThreshold'], 'number', 'min' => 0, 'max' => 1],
            [['minimumSimilarityThreshold'], 'default', 'value' => 0.90],
            [['rrfK'], 'integer', 'min' => 1, 'max' => 1000],
            [['rrfK'], 'default', 'value' => 60],
            [['rrfSemanticWeight', 'rrfBm25Weight'], 'number', 'min' => 0, 'max' => 1],
            [['rrfSemanticWeight'], 'default', 'value' => 0.3],
            [['rrfBm25Weight'], 'default', 'value' => 0.7],

            // RAG Search validation
            [['ragModel'], 'required'],
            [['ragModel'], 'string'],
            [['ragModel'], 'in', 'range' => ['gpt-5.4-nano']],
            [['ragCustomPrompt'], 'string'],

            // Content Chunking validation
            [['minChunkTokens'], 'integer', 'min' => 10, 'max' => 500],
            [['minChunkTokens'], 'default', 'value' => 100],
            [['targetChunkTokens'], 'integer', 'min' => 100, 'max' => 1000],
            [['targetChunkTokens'], 'default', 'value' => 400],
            [['maxChunkTokens'], 'integer', 'min' => 200, 'max' => 2000],
            [['maxChunkTokens'], 'default', 'value' => 600],
            [['overlapTokens'], 'integer', 'min' => 0, 'max' => 200],
            [['overlapTokens'], 'default', 'value' => 40],
            [['chunkThresholdTokens'], 'integer', 'min' => 100, 'max' => 1000],
            [['chunkThresholdTokens'], 'default', 'value' => 500],

            // Cache validation
            [['embeddingCacheTtl'], 'integer', 'min' => 0, 'max' => 2592000],
            [['embeddingCacheTtl'], 'default', 'value' => 604800],

            // Hybrid Search Advanced validation
            [['minSemanticThreshold'], 'number', 'min' => 0, 'max' => 1],
            [['minSemanticThreshold'], 'default', 'value' => 0.20],
            [['singleSignalPenalty'], 'number', 'min' => 0, 'max' => 1],
            [['singleSignalPenalty'], 'default', 'value' => 0.5],
            [['maxSemanticResults'], 'integer', 'min' => 10, 'max' => 500],
            [['maxSemanticResults'], 'default', 'value' => 100],

            // Display validation
            [['excerptLength'], 'integer', 'min' => 50, 'max' => 500],
            [['excerptLength'], 'default', 'value' => 200],

            // Indexing filter validation
            [['indexableSections'], 'each', 'rule' => ['string']],

            // Vector dimensions validation
            [['vectorDimensions'], 'integer'],
            [['vectorDimensions'], 'in', 'range' => [512, 1024, 1536, 3072]],
            [['vectorDimensions'], 'default', 'value' => 1536],

            // History tracking validation
            [['historyEnabled'], 'boolean'],
            [['historyEnabled'], 'default', 'value' => true],
            [['historyRetentionDays'], 'integer', 'min' => 1, 'max' => 365],
            [['historyRetentionDays'], 'default', 'value' => 30],
        ];
    }

    /**
     * Get the OpenAI API key, resolving any environment variable reference (e.g. `$OPENAI_API_KEY`).
     */
    public function getOpenaiApiKey(): ?string
    {
        return $this->parseEnvOrNull($this->openaiApiKey);
    }

    /**
     * Get the PostgreSQL host, resolving any environment variable reference.
     */
    public function getPostgresqlHost(): ?string
    {
        return $this->parseEnvOrNull($this->postgresqlHost);
    }

    /**
     * Get the PostgreSQL database name, resolving any environment variable reference.
     */
    public function getPostgresqlDatabase(): ?string
    {
        return $this->parseEnvOrNull($this->postgresqlDatabase);
    }

    /**
     * Get the PostgreSQL user, resolving any environment variable reference.
     */
    public function getPostgresqlUser(): ?string
    {
        return $this->parseEnvOrNull($this->postgresqlUser);
    }

    /**
     * Get the PostgreSQL password, resolving any environment variable reference.
     */
    public function getPostgresqlPassword(): ?string
    {
        return $this->parseEnvOrNull($this->postgresqlPassword);
    }

    /**
     * Parse an environment variable reference, returning null for empty values.
     */
    private function parseEnvOrNull(?string $value): ?string
    {
        return empty($value) ? null : App::parseEnv($value);
    }

    /**
     * Get the PostgreSQL port, resolving any environment variable reference.
     */
    public function getPostgresqlPort(): int
    {
        if (is_string($this->postgresqlPort)) {
            return (int)App::parseEnv($this->postgresqlPort);
        }

        return (int)$this->postgresqlPort;
    }

    /**
     * Get the PostgreSQL SSL mode, resolving any environment variable reference.
     */
    public function getPostgresqlSslMode(): string
    {
        return App::parseEnv($this->postgresqlSslMode);
    }

    /**
     * Get the API token, resolving any environment variable reference.
     */
    public function getApiToken(): ?string
    {
        return $this->parseEnvOrNull($this->apiToken);
    }
}

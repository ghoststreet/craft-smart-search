<?php

namespace ghoststreet\craftaisearch\models;

use craft\base\Model;
use craft\helpers\App;

/**
 * ai-search settings
 */
class Settings extends Model
{
    public const IDENTIFIER_REGEX = '/^[a-zA-Z_][a-zA-Z0-9_]{0,62}$/';

    public const SCENARIO_QUICK_START = 'quickStart';
    public const SCENARIO_BEHAVIOR    = 'behavior';
    public const SCENARIO_DATABASE    = 'database';
    public const SCENARIO_ADVANCED    = 'advanced';

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

    public string $vectorsTableName = 'aisearch_vectors';
    public string $vectorsSchemaName = 'public';

    public float $minimumSimilarityThreshold = 0.90;
    public int $rrfK = 60;
    public float $rrfSemanticWeight = 0.3;
    public float $rrfBm25Weight = 0.7;

    public string $ragModel = 'gpt-5.4-nano';
    public ?string $ragCustomPrompt = null;

    public int $maxPromptTokens = 6000;

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

    public const VECTOR_DIMENSIONS = 1536;

    public int $historyRetentionDays = 30;

    public ?string $allowedOrigins = null;

    public int $rateLimitSearchPerMinute = 10;
    public int $rateLimitSearchPerHour = 60;
    public int $rateLimitRagPerMinute = 3;
    public int $rateLimitRagPerHour = 20;

    public int $ragConcurrencyPerIp = 2;
    public int $ragConcurrencyGlobal = 10;

    public float $costBudgetDailyGlobal = 20.0;

    public bool $exposeStackTraces = false;

    /**
     * Maps each CP settings tab to the attributes it owns. Drives both `scenarios()`
     * (mass-assignment filtering) and the `on` tag on every rule below.
     */
    private const SCENARIO_ATTRIBUTES = [
        self::SCENARIO_QUICK_START => ['openaiApiKey', 'hybridEmbeddingModel', 'ragModel', 'costBudgetDailyGlobal'],
        self::SCENARIO_BEHAVIOR    => [
            'minimumSimilarityThreshold', 'rrfSemanticWeight', 'rrfBm25Weight', 'rrfK',
            'minSemanticThreshold', 'singleSignalPenalty', 'maxSemanticResults',
            'embeddingCacheTtl',
            'rateLimitSearchPerMinute', 'rateLimitSearchPerHour',
            'rateLimitRagPerMinute', 'rateLimitRagPerHour',
            'ragConcurrencyPerIp', 'ragConcurrencyGlobal',
        ],
        self::SCENARIO_DATABASE    => [
            'postgresqlHost', 'postgresqlPort', 'postgresqlDatabase', 'postgresqlUser',
            'postgresqlPassword', 'postgresqlSslMode',
            'vectorsSchemaName', 'vectorsTableName',
        ],
        self::SCENARIO_ADVANCED    => [
            'minChunkTokens', 'targetChunkTokens', 'maxChunkTokens', 'overlapTokens', 'chunkThresholdTokens',
            'ragEmbeddingModel', 'maxPromptTokens', 'ragCustomPrompt',
            'apiToken', 'allowedOrigins', 'exposeStackTraces',
            'historyRetentionDays', 'excerptLength',
        ],
    ];

    public function scenarios(): array
    {
        return array_merge(parent::scenarios(), self::SCENARIO_ATTRIBUTES);
    }

    /**
     * Validation rules for all plugin settings, grouped by feature area.
     *
     * Each rule is tagged with `on` so it only runs under its tab's scenario
     * (and the default scenario for programmatic saves). `default` rules are
     * left untagged so missing numeric values are filled in regardless of which
     * tab triggered the save.
     */
    public function rules(): array
    {
        $quickStart = [self::SCENARIO_DEFAULT, self::SCENARIO_QUICK_START];
        $behavior   = [self::SCENARIO_DEFAULT, self::SCENARIO_BEHAVIOR];
        $database   = [self::SCENARIO_DEFAULT, self::SCENARIO_DATABASE];
        $advanced   = [self::SCENARIO_DEFAULT, self::SCENARIO_ADVANCED];

        return [
            // OpenAI API validation
            [['openaiApiKey'], 'required', 'on' => $quickStart],
            [['openaiApiKey'], 'validateEnvSecret', 'on' => $quickStart],

            // API token validation
            [['apiToken'], 'string', 'on' => $advanced],
            [['apiToken'], 'validateOptionalEnvSecret', 'on' => $advanced],

            // Embedding model validation
            [['hybridEmbeddingModel'], 'required', 'on' => $quickStart],
            [['hybridEmbeddingModel'], 'string', 'on' => $quickStart],
            [['hybridEmbeddingModel'], 'in', 'range' => ['text-embedding-3-small', 'text-embedding-3-large'], 'on' => $quickStart],
            [['ragEmbeddingModel'], 'required', 'on' => $advanced],
            [['ragEmbeddingModel'], 'string', 'on' => $advanced],
            [['ragEmbeddingModel'], 'in', 'range' => ['text-embedding-3-small', 'text-embedding-3-large'], 'on' => $advanced],

            // PostgreSQL validation
            [['postgresqlHost', 'postgresqlDatabase', 'postgresqlUser', 'postgresqlPassword', 'postgresqlPort', 'postgresqlSslMode'], 'required', 'on' => $database],
            [['postgresqlHost', 'postgresqlDatabase', 'postgresqlUser', 'postgresqlSslMode'], 'string', 'on' => $database],
            [['postgresqlPort'], function ($attribute) {
                $value = $this->$attribute;
                if (!is_string($value) && !is_int($value)) {
                    $this->addError($attribute, 'Port must be a string or integer.');
                }
            }, 'on' => $database],
            [['postgresqlPassword'], 'string', 'on' => $database],
            [['postgresqlPassword'], 'validateEnvSecret', 'on' => $database],
            [['postgresqlPort'], 'default', 'value' => 5432],
            [['postgresqlSslMode'], 'required', 'on' => $database],
            [['postgresqlSslMode'], 'in', 'range' => ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'], 'on' => $database],

            // Vectors table identifier validation
            [['vectorsTableName', 'vectorsSchemaName'], 'required', 'on' => $database],
            [['vectorsTableName', 'vectorsSchemaName'], 'match', 'pattern' => self::IDENTIFIER_REGEX,
                'message' => '{attribute} must be a valid Postgres identifier (letters, digits, underscores; max 63 chars).',
                'on' => $database],

            // Hybrid search validation
            [['minimumSimilarityThreshold'], 'number', 'min' => 0, 'max' => 1, 'on' => $behavior],
            [['minimumSimilarityThreshold'], 'default', 'value' => 0.90],
            [['rrfK'], 'integer', 'min' => 1, 'max' => 1000, 'on' => $behavior],
            [['rrfK'], 'default', 'value' => 60],
            [['rrfSemanticWeight', 'rrfBm25Weight'], 'number', 'min' => 0, 'max' => 1, 'on' => $behavior],
            [['rrfSemanticWeight'], 'default', 'value' => 0.3],
            [['rrfBm25Weight'], 'default', 'value' => 0.7],

            // RAG Search validation
            [['ragModel'], 'required', 'on' => $quickStart],
            [['ragModel'], 'string', 'on' => $quickStart],
            [['ragModel'], 'in', 'range' => ['gpt-5.4-nano'], 'on' => $quickStart],
            [['ragCustomPrompt'], 'string', 'on' => $advanced],
            [['maxPromptTokens'], 'integer', 'min' => 500, 'max' => 100000, 'on' => $advanced],
            [['maxPromptTokens'], 'default', 'value' => 6000],

            // Content Chunking validation
            [['minChunkTokens'], 'integer', 'min' => 10, 'max' => 500, 'on' => $advanced],
            [['minChunkTokens'], 'default', 'value' => 100],
            [['targetChunkTokens'], 'integer', 'min' => 100, 'max' => 1000, 'on' => $advanced],
            [['targetChunkTokens'], 'default', 'value' => 400],
            [['maxChunkTokens'], 'integer', 'min' => 200, 'max' => 2000, 'on' => $advanced],
            [['maxChunkTokens'], 'default', 'value' => 600],
            [['overlapTokens'], 'integer', 'min' => 0, 'max' => 200, 'on' => $advanced],
            [['overlapTokens'], 'default', 'value' => 40],
            [['chunkThresholdTokens'], 'integer', 'min' => 100, 'max' => 1000, 'on' => $advanced],
            [['chunkThresholdTokens'], 'default', 'value' => 500],

            // Cache validation
            [['embeddingCacheTtl'], 'integer', 'min' => 0, 'max' => 2592000, 'on' => $behavior],
            [['embeddingCacheTtl'], 'default', 'value' => 604800],

            // Hybrid Search Advanced validation
            [['minSemanticThreshold'], 'number', 'min' => 0, 'max' => 1, 'on' => $behavior],
            [['minSemanticThreshold'], 'default', 'value' => 0.20],
            [['singleSignalPenalty'], 'number', 'min' => 0, 'max' => 1, 'on' => $behavior],
            [['singleSignalPenalty'], 'default', 'value' => 0.5],
            [['maxSemanticResults'], 'integer', 'min' => 10, 'max' => 500, 'on' => $behavior],
            [['maxSemanticResults'], 'default', 'value' => 100],

            // Display validation
            [['excerptLength'], 'integer', 'min' => 50, 'max' => 500, 'on' => $advanced],
            [['excerptLength'], 'default', 'value' => 200],

            // History tracking validation
            [['historyRetentionDays'], 'integer', 'min' => 1, 'max' => 365, 'on' => $advanced],
            [['historyRetentionDays'], 'default', 'value' => 30],

            // Security / rate-limit / budget validation
            [['allowedOrigins'], 'string', 'on' => $advanced],
            [['rateLimitSearchPerMinute', 'rateLimitSearchPerHour',
              'rateLimitRagPerMinute', 'rateLimitRagPerHour',
              'ragConcurrencyPerIp', 'ragConcurrencyGlobal'], 'integer', 'min' => 1, 'max' => 100000, 'on' => $behavior],
            [['costBudgetDailyGlobal'], 'number', 'min' => 0, 'on' => $quickStart],
            [['exposeStackTraces'], 'boolean', 'on' => $advanced],
            [['exposeStackTraces'], 'default', 'value' => false],
        ];
    }

    public function validateEnvSecret(string $attribute): void
    {
        $value = $this->$attribute;

        if (!is_string($value) || $value === '') {
            return;
        }

        if (!str_starts_with($value, '$')) {
            $this->addError($attribute, 'Must be an environment variable reference (e.g. $OPENAI_API_KEY). Plain-text secrets are not allowed.');
            return;
        }

        $resolved = App::parseEnv($value);

        if ($resolved === null || $resolved === '' || $resolved === $value) {
            $this->addError($attribute, 'Environment variable ' . $value . ' is not set or is empty.');
        }
    }

    public function validateOptionalEnvSecret(string $attribute): void
    {
        $value = $this->$attribute;

        if (!is_string($value) || $value === '') {
            return;
        }

        $this->validateEnvSecret($attribute);
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

    public function getQualifiedVectorsTable(): string
    {
        $schema = $this->vectorsSchemaName;
        $table = $this->vectorsTableName;

        if (!preg_match(self::IDENTIFIER_REGEX, $schema) || !preg_match(self::IDENTIFIER_REGEX, $table)) {
            throw new \RuntimeException('Vectors table/schema name failed identifier validation.');
        }

        return "\"{$schema}\".\"{$table}\"";
    }

    /** @return string[] */
    public function getAllowedOriginsList(): array
    {
        $raw = $this->allowedOrigins ? (string)App::parseEnv($this->allowedOrigins) : '';

        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}

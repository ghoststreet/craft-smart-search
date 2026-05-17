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

    public const SCENARIO_CONNECTIONS   = 'connections';
    public const SCENARIO_INDEXING      = 'indexing';
    public const SCENARIO_HYBRID_SEARCH = 'hybridSearch';
    public const SCENARIO_AI_ANSWERS    = 'aiAnswers';
    public const SCENARIO_OPERATIONS    = 'operations';

    public ?string $openaiApiKey = null;
    public ?string $apiToken = null;

    public string $hybridEmbeddingModel = 'text-embedding-3-small';

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

    /**
     * Maps each CP settings tab to the attributes it owns. Drives both `scenarios()`
     * (mass-assignment filtering) and the `on` tag on every rule below.
     */
    private const SCENARIO_ATTRIBUTES = [
        self::SCENARIO_CONNECTIONS   => [
            'openaiApiKey',
            'postgresqlHost', 'postgresqlPort', 'postgresqlDatabase', 'postgresqlUser',
            'postgresqlPassword', 'postgresqlSslMode',
        ],
        self::SCENARIO_INDEXING      => [
            'minChunkTokens', 'targetChunkTokens', 'maxChunkTokens', 'overlapTokens', 'chunkThresholdTokens',
            'vectorsSchemaName', 'vectorsTableName',
            'embeddingCacheTtl',
        ],
        self::SCENARIO_HYBRID_SEARCH => [
            'hybridEmbeddingModel',
            'minimumSimilarityThreshold', 'rrfSemanticWeight', 'rrfBm25Weight',
            'rrfK', 'minSemanticThreshold', 'singleSignalPenalty', 'maxSemanticResults',
            'excerptLength',
            'rateLimitSearchPerMinute', 'rateLimitSearchPerHour',
        ],
        self::SCENARIO_AI_ANSWERS    => [
            'ragModel', 'maxPromptTokens', 'ragCustomPrompt',
            'costBudgetDailyGlobal',
            'rateLimitRagPerMinute', 'rateLimitRagPerHour',
            'ragConcurrencyPerIp', 'ragConcurrencyGlobal',
        ],
        self::SCENARIO_OPERATIONS    => [
            'apiToken', 'allowedOrigins',
            'historyRetentionDays',
        ],
    ];

    public function scenarios(): array
    {
        return array_merge(parent::scenarios(), self::SCENARIO_ATTRIBUTES);
    }

    private const TAB_TO_SCENARIO = [
        'tab-connections'   => self::SCENARIO_CONNECTIONS,
        'tab-indexing'      => self::SCENARIO_INDEXING,
        'tab-hybrid-search' => self::SCENARIO_HYBRID_SEARCH,
        'tab-ai-answers'    => self::SCENARIO_AI_ANSWERS,
        'tab-operations'    => self::SCENARIO_OPERATIONS,
    ];

    /**
     * Flat list of validation errors for every attribute owned by the given tab.
     * Used by the settings template to drive Craft's native per-tab error badge.
     *
     * @return string[]
     */
    public function errorsForTab(string $tabId): array
    {
        $scenario = self::TAB_TO_SCENARIO[$tabId] ?? null;
        if ($scenario === null) {
            return [];
        }

        $errors = [];
        foreach (self::SCENARIO_ATTRIBUTES[$scenario] as $attribute) {
            foreach ($this->getErrors($attribute) as $message) {
                $errors[] = $message;
            }
        }
        return $errors;
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
        $connections  = [self::SCENARIO_DEFAULT, self::SCENARIO_CONNECTIONS];
        $indexing     = [self::SCENARIO_DEFAULT, self::SCENARIO_INDEXING];
        $hybridSearch = [self::SCENARIO_DEFAULT, self::SCENARIO_HYBRID_SEARCH];
        $aiAnswers    = [self::SCENARIO_DEFAULT, self::SCENARIO_AI_ANSWERS];
        $operations   = [self::SCENARIO_DEFAULT, self::SCENARIO_OPERATIONS];

        return [
            // OpenAI API key — Connections
            [['openaiApiKey'], 'required', 'on' => $connections],
            [['openaiApiKey'], 'validateEnvSecret', 'on' => $connections],

            // PostgreSQL connection — Connections
            [['postgresqlHost', 'postgresqlDatabase', 'postgresqlUser', 'postgresqlPassword', 'postgresqlPort', 'postgresqlSslMode'], 'required', 'on' => $connections],
            [['postgresqlHost', 'postgresqlDatabase', 'postgresqlUser', 'postgresqlSslMode'], 'string', 'on' => $connections],
            [['postgresqlPort'], function ($attribute) {
                $value = $this->$attribute;
                if (!is_string($value) && !is_int($value)) {
                    $this->addError($attribute, 'Port must be a string or integer.');
                }
            }, 'on' => $connections],
            [['postgresqlPassword'], 'string', 'on' => $connections],
            [['postgresqlPassword'], 'validateEnvSecret', 'on' => $connections],
            [['postgresqlPort'], 'default', 'value' => 5432],
            [['postgresqlSslMode'], 'in', 'range' => ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'], 'on' => $connections],

            // Vector storage identifiers — Indexing
            [['vectorsTableName', 'vectorsSchemaName'], 'required', 'on' => $indexing],
            [['vectorsTableName', 'vectorsSchemaName'], 'match', 'pattern' => self::IDENTIFIER_REGEX,
                'message' => '{attribute} must be a valid Postgres identifier (letters, digits, underscores; max 63 chars).',
                'on' => $indexing],

            // Content chunking — Indexing
            [['minChunkTokens'], 'integer', 'min' => 10, 'max' => 500, 'on' => $indexing],
            [['minChunkTokens'], 'default', 'value' => 100],
            [['targetChunkTokens'], 'integer', 'min' => 100, 'max' => 1000, 'on' => $indexing],
            [['targetChunkTokens'], 'default', 'value' => 400],
            [['maxChunkTokens'], 'integer', 'min' => 200, 'max' => 2000, 'on' => $indexing],
            [['maxChunkTokens'], 'default', 'value' => 600],
            [['overlapTokens'], 'integer', 'min' => 0, 'max' => 200, 'on' => $indexing],
            [['overlapTokens'], 'default', 'value' => 40],
            [['chunkThresholdTokens'], 'integer', 'min' => 100, 'max' => 1000, 'on' => $indexing],
            [['chunkThresholdTokens'], 'default', 'value' => 500],

            // Embedding cache TTL — Indexing
            [['embeddingCacheTtl'], 'integer', 'min' => 0, 'max' => 2592000, 'on' => $indexing],
            [['embeddingCacheTtl'], 'default', 'value' => 604800],

            // Hybrid Search — corpus embedding model
            [['hybridEmbeddingModel'], 'required', 'on' => $hybridSearch],
            [['hybridEmbeddingModel'], 'string', 'on' => $hybridSearch],
            [['hybridEmbeddingModel'], 'in', 'range' => ['text-embedding-3-small', 'text-embedding-3-large'], 'on' => $hybridSearch],

            // Hybrid Search — ranking
            [['minimumSimilarityThreshold'], 'number', 'min' => 0, 'max' => 1, 'on' => $hybridSearch],
            [['minimumSimilarityThreshold'], 'default', 'value' => 0.90],
            [['rrfK'], 'integer', 'min' => 1, 'max' => 1000, 'on' => $hybridSearch],
            [['rrfK'], 'default', 'value' => 60],
            [['rrfSemanticWeight', 'rrfBm25Weight'], 'number', 'min' => 0, 'max' => 1, 'on' => $hybridSearch],
            [['rrfSemanticWeight'], 'default', 'value' => 0.3],
            [['rrfBm25Weight'], 'default', 'value' => 0.7],
            [['minSemanticThreshold'], 'number', 'min' => 0, 'max' => 1, 'on' => $hybridSearch],
            [['minSemanticThreshold'], 'default', 'value' => 0.20],
            [['singleSignalPenalty'], 'number', 'min' => 0, 'max' => 1, 'on' => $hybridSearch],
            [['singleSignalPenalty'], 'default', 'value' => 0.5],
            [['maxSemanticResults'], 'integer', 'min' => 10, 'max' => 500, 'on' => $hybridSearch],
            [['maxSemanticResults'], 'default', 'value' => 100],

            // Hybrid Search — result display
            [['excerptLength'], 'integer', 'min' => 50, 'max' => 500, 'on' => $hybridSearch],
            [['excerptLength'], 'default', 'value' => 200],

            // Hybrid Search — rate limits (0 disables the window)
            [['rateLimitSearchPerMinute', 'rateLimitSearchPerHour'], 'integer', 'min' => 0, 'max' => 100000, 'on' => $hybridSearch],

            // AI Answers — models and prompt
            [['ragModel'], 'required', 'on' => $aiAnswers],
            [['ragModel'], 'string', 'on' => $aiAnswers],
            [['ragModel'], 'in', 'range' => ['gpt-5.4-nano'], 'on' => $aiAnswers],
            [['ragCustomPrompt'], 'string', 'on' => $aiAnswers],
            [['maxPromptTokens'], 'integer', 'min' => 500, 'max' => 100000, 'on' => $aiAnswers],
            [['maxPromptTokens'], 'default', 'value' => 6000],

            // AI Answers — budget + limits
            [['costBudgetDailyGlobal'], 'number', 'min' => 0, 'on' => $aiAnswers],
            [['rateLimitRagPerMinute', 'rateLimitRagPerHour'], 'integer', 'min' => 0, 'max' => 100000, 'on' => $aiAnswers],
            [['ragConcurrencyPerIp', 'ragConcurrencyGlobal'], 'integer', 'min' => 1, 'max' => 100000, 'on' => $aiAnswers],

            // Operations — API access
            [['apiToken'], 'string', 'on' => $operations],
            [['apiToken'], 'validateOptionalEnvSecret', 'on' => $operations],
            [['allowedOrigins'], 'string', 'on' => $operations],
            [['allowedOrigins'], 'validateAllowedOrigins', 'on' => $operations],

            // Operations — diagnostics
            [['historyRetentionDays'], 'integer', 'min' => 1, 'max' => 365, 'on' => $operations],
            [['historyRetentionDays'], 'default', 'value' => 30],
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

    public function validateAllowedOrigins(string $attribute): void
    {
        $value = $this->$attribute;

        if (!is_string($value) || $value === '') {
            return;
        }

        if (str_starts_with(trim($value), '$')) {
            return;
        }

        foreach (explode(',', $value) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if (!preg_match('#^https?://[^/\s:]+(:\d+)?$#', $entry)) {
                $this->addError($attribute, 'Each origin must be like https://app.example.com (scheme, host, optional port; no path).');
                return;
            }
        }
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

<?php

namespace ghoststreet\craftsmartsearch\models;

use craft\base\Model;
use craft\helpers\App;
use RuntimeException;

/**
 * smart-search settings
 */
class Settings extends Model
{
    public const IDENTIFIER_REGEX = '/^[a-zA-Z_][a-zA-Z0-9_]{0,62}$/';

    public const SCENARIO_CONNECTIONS = 'connections';
    public const SCENARIO_CONNECTIONS_OPENAI = 'connections.openai';
    public const SCENARIO_CONNECTIONS_POSTGRES = 'connections.postgres';
    public const SCENARIO_INDEXING = 'indexing';
    public const SCENARIO_SMART_SEARCH = 'smartSearch';
    public const SCENARIO_AI_ANSWER = 'aiAnswer';
    public const SCENARIO_ADVANCED = 'advanced';

    public ?string $openaiApiKey = null;
    public ?string $apiToken = null;

    public string $embeddingModel = 'text-embedding-3-small';

    public ?string $postgresqlHost = null;
    public string|int $postgresqlPort = 5432;
    public ?string $postgresqlDatabase = null;
    public ?string $postgresqlUser = null;
    public ?string $postgresqlPassword = null;
    public string $postgresqlSslMode = 'require';

    public string $vectorsTableName = '';
    public string $vectorsSchemaName = 'public';

    public string $termsTableName = 'smart_search_terms';

    public float $rrfSemanticWeight = 0.3;
    public float $rrfKeywordWeight = 0.7;

    public string $aiAnswerModel = 'gpt-5.4-nano';
    public ?string $aiAnswerCustomPrompt = null;

    public int $maxPromptTokens = 12000;

    public int $minChunkTokens = 100;
    public int $targetChunkTokens = 400;
    public int $maxChunkTokens = 600;
    public int $overlapTokens = 40;
    public int $chunkThresholdTokens = 500;

    public int $embeddingCacheTtlDays = 7;

    public float $minSemanticThreshold = 0.15;
    public int $maxSemanticResults = 100;

    public int $excerptLength = 200;

    public bool $enableTypoTolerance = true;

    public const VECTOR_DIMENSIONS = 1536;

    public ?string $allowedOrigins = null;

    public int $rateLimitSearchPerMinute = 10;
    public int $rateLimitSearchPerHour = 30;
    public int $rateLimitAiAnswerPerMinute = 30;
    public int $rateLimitAiAnswerPerHour = 30;

    public int $aiAnswerConcurrencyPerIp = 2;
    public int $aiAnswerConcurrencyGlobal = 10;

    public float $costBudgetDailyGlobal = 3.0;

    /**
     * Maps each CP settings tab to the attributes it owns. Drives both `scenarios()`
     * (mass-assignment filtering) and the `on` tag on every rule below.
     */
    public const SCENARIO_ATTRIBUTES = [
        self::SCENARIO_CONNECTIONS => [
            'openaiApiKey',
            'postgresqlHost', 'postgresqlPort', 'postgresqlDatabase', 'postgresqlUser',
            'postgresqlPassword', 'postgresqlSslMode',
            'vectorsSchemaName', 'vectorsTableName',
        ],
        self::SCENARIO_CONNECTIONS_OPENAI => [
            'openaiApiKey',
            'embeddingModel',
            'aiAnswerModel',
        ],
        self::SCENARIO_CONNECTIONS_POSTGRES => [
            'postgresqlHost', 'postgresqlPort', 'postgresqlDatabase', 'postgresqlUser',
            'postgresqlPassword', 'postgresqlSslMode',
            'vectorsSchemaName', 'vectorsTableName',
            'termsTableName',
        ],
        self::SCENARIO_INDEXING => [
            'minChunkTokens', 'targetChunkTokens', 'maxChunkTokens', 'overlapTokens', 'chunkThresholdTokens',
            'embeddingCacheTtlDays',
        ],
        self::SCENARIO_SMART_SEARCH => [
            'rrfSemanticWeight', 'rrfKeywordWeight',
            'minSemanticThreshold', 'maxSemanticResults',
            'excerptLength',
            'enableTypoTolerance',
            'rateLimitSearchPerMinute', 'rateLimitSearchPerHour',
        ],
        self::SCENARIO_AI_ANSWER => [
            'maxPromptTokens', 'aiAnswerCustomPrompt',
            'costBudgetDailyGlobal',
            'rateLimitAiAnswerPerMinute', 'rateLimitAiAnswerPerHour',
            'aiAnswerConcurrencyPerIp', 'aiAnswerConcurrencyGlobal',
        ],
        self::SCENARIO_ADVANCED => [
            'apiToken', 'allowedOrigins',
        ],
    ];

    public function scenarios(): array
    {
        return array_merge(parent::scenarios(), self::SCENARIO_ATTRIBUTES);
    }

    private const TAB_TO_SCENARIO = [
        'tab-connections' => self::SCENARIO_CONNECTIONS,
        'tab-indexing' => self::SCENARIO_INDEXING,
        'tab-smart-search' => self::SCENARIO_SMART_SEARCH,
        'tab-ai-answer' => self::SCENARIO_AI_ANSWER,
        'tab-advanced' => self::SCENARIO_ADVANCED,
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
        return $scenario === null ? [] : $this->errorsForScenario($scenario);
    }

    /**
     * Flat list of validation errors for every attribute owned by the given
     * scenario. Used by the per-page settings templates to surface errors.
     *
     * @return string[]
     */
    public function errorsForScenario(string $scenario): array
    {
        $attributes = self::SCENARIO_ATTRIBUTES[$scenario] ?? null;
        if ($attributes === null) {
            return [];
        }

        $errors = [];
        foreach ($attributes as $attribute) {
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
        $openai = [self::SCENARIO_DEFAULT, self::SCENARIO_CONNECTIONS, self::SCENARIO_CONNECTIONS_OPENAI];
        $postgres = [self::SCENARIO_DEFAULT, self::SCENARIO_CONNECTIONS, self::SCENARIO_CONNECTIONS_POSTGRES];
        $indexing = [self::SCENARIO_DEFAULT, self::SCENARIO_INDEXING];
        $smartSearch = [self::SCENARIO_DEFAULT, self::SCENARIO_SMART_SEARCH];
        $aiAnswer = [self::SCENARIO_DEFAULT, self::SCENARIO_AI_ANSWER];
        $advanced = [self::SCENARIO_DEFAULT, self::SCENARIO_ADVANCED];

        return [
            // OpenAI API key — Connections / OpenAI
            [['openaiApiKey'], 'required', 'on' => $openai],
            [['openaiApiKey'], 'validateEnvSecret', 'on' => $openai],

            // PostgreSQL connection — Connections / PostgreSQL
            [['postgresqlHost', 'postgresqlDatabase', 'postgresqlUser', 'postgresqlPassword', 'postgresqlPort', 'postgresqlSslMode'], 'required', 'on' => $postgres],
            [['postgresqlHost', 'postgresqlDatabase', 'postgresqlUser', 'postgresqlSslMode'], 'string', 'on' => $postgres],
            [['postgresqlPort'], function($attribute) {
                $value = $this->$attribute;
                if (!is_string($value) && !is_int($value)) {
                    $this->addError($attribute, 'Port must be a string or integer.');
                }
            }, 'on' => $postgres],
            [['postgresqlPassword'], 'string', 'on' => $postgres],
            [['postgresqlPassword'], 'validateEnvSecret', 'on' => $postgres],
            [['postgresqlPort'], 'default', 'value' => 5432],
            [['postgresqlSslMode'], 'in', 'range' => ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'], 'on' => $postgres],

            // Vector storage identifiers — Connections / PostgreSQL
            [['vectorsSchemaName'], 'default', 'value' => 'public'],
            [['vectorsTableName'], 'required', 'on' => $postgres],
            [['vectorsTableName', 'vectorsSchemaName'], 'match', 'pattern' => self::IDENTIFIER_REGEX,
                'message' => '{attribute} must be a valid Postgres identifier (letters, digits, underscores; max 63 chars).',
                'on' => $postgres, ],

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
            [['minChunkTokens', 'targetChunkTokens', 'maxChunkTokens', 'overlapTokens'], 'validateChunkSizing', 'on' => $indexing],

            // Embedding cache TTL — Indexing
            [['embeddingCacheTtlDays'], 'integer', 'min' => 0, 'max' => 30, 'on' => $indexing],
            [['embeddingCacheTtlDays'], 'default', 'value' => 7],

            // Embedding model lives with the OpenAI key — both are OpenAI account choices.
            [['embeddingModel'], 'required', 'on' => $openai],
            [['embeddingModel'], 'string', 'on' => $openai],
            [['embeddingModel'], 'in', 'range' => ['text-embedding-3-small', 'text-embedding-3-large'], 'on' => $openai],

            // Smart Search — ranking
            [['rrfSemanticWeight', 'rrfKeywordWeight'], 'number', 'min' => 0, 'max' => 1, 'on' => $smartSearch],
            [['rrfSemanticWeight'], 'default', 'value' => 0.3],
            [['rrfKeywordWeight'], 'default', 'value' => 0.7],
            [['rrfSemanticWeight', 'rrfKeywordWeight'], 'validateRankingWeights', 'on' => $smartSearch],
            [['minSemanticThreshold'], 'number', 'min' => 0, 'max' => 1, 'on' => $smartSearch],
            [['minSemanticThreshold'], 'default', 'value' => 0.15],
            [['maxSemanticResults'], 'integer', 'min' => 10, 'max' => 500, 'on' => $smartSearch],
            [['maxSemanticResults'], 'default', 'value' => 100],

            // Smart Search — result display
            [['excerptLength'], 'integer', 'min' => 50, 'max' => 500, 'on' => $smartSearch],
            [['excerptLength'], 'default', 'value' => 200],

            [['enableTypoTolerance'], 'boolean', 'on' => $smartSearch],
            [['enableTypoTolerance'], 'default', 'value' => true],

            [['termsTableName'], 'required', 'on' => $postgres],
            [['termsTableName'], 'default', 'value' => 'smart_search_terms'],
            [['termsTableName'], 'match', 'pattern' => self::IDENTIFIER_REGEX,
                'message' => '{attribute} must be a valid Postgres identifier (letters, digits, underscores; max 63 chars).',
                'on' => $postgres, ],

            // Smart Search — rate limits (0 disables the window)
            [['rateLimitSearchPerMinute', 'rateLimitSearchPerHour'], 'integer', 'min' => 0, 'max' => 100000, 'on' => $smartSearch],

            // Answer model lives with the OpenAI key — both are OpenAI account choices.
            [['aiAnswerModel'], 'required', 'on' => $openai],
            [['aiAnswerModel'], 'string', 'on' => $openai],
            [['aiAnswerModel'], 'in', 'range' => ['gpt-5.4-nano', 'gpt-5.4-mini', 'gpt-5.4'], 'on' => $openai],

            // AI Answer — prompt + token budget
            [['aiAnswerCustomPrompt'], 'string', 'on' => $aiAnswer],
            [['maxPromptTokens'], 'integer', 'min' => 500, 'max' => 100000, 'on' => $aiAnswer],

            // AI Answer — budget + limits
            [['costBudgetDailyGlobal'], 'number', 'min' => 0, 'on' => $aiAnswer],
            [['rateLimitAiAnswerPerMinute', 'rateLimitAiAnswerPerHour'], 'integer', 'min' => 0, 'max' => 100000, 'on' => $aiAnswer],
            [['aiAnswerConcurrencyPerIp', 'aiAnswerConcurrencyGlobal'], 'integer', 'min' => 1, 'max' => 100000, 'on' => $aiAnswer],

            // Advanced — API access
            [['apiToken'], 'string', 'on' => $advanced],
            [['apiToken'], 'validateOptionalEnvSecret', 'on' => $advanced],
            [['allowedOrigins'], 'string', 'on' => $advanced],
            [['allowedOrigins'], 'validateAllowedOrigins', 'on' => $advanced],
        ];
    }

    public function validateChunkSizing(string $attribute): void
    {
        if ($this->hasErrors('minChunkTokens') || $this->hasErrors('targetChunkTokens')
            || $this->hasErrors('maxChunkTokens') || $this->hasErrors('overlapTokens')) {
            return;
        }

        switch ($attribute) {
            case 'minChunkTokens':
                if ($this->minChunkTokens >= $this->targetChunkTokens) {
                    $this->addError($attribute, 'Smallest chunk size must be less than the target chunk size.');
                }
                break;
            case 'targetChunkTokens':
                if ($this->targetChunkTokens >= $this->maxChunkTokens) {
                    $this->addError($attribute, 'Target chunk size must be less than the largest chunk size.');
                }
                break;
            case 'overlapTokens':
                if ($this->overlapTokens >= $this->minChunkTokens) {
                    $this->addError($attribute, 'Chunk overlap must be less than the smallest chunk size.');
                }
                break;
        }
    }

    public function validateRankingWeights(string $attribute): void
    {
        if ($this->hasErrors('rrfSemanticWeight') || $this->hasErrors('rrfKeywordWeight')) {
            return;
        }

        $total = (float)$this->rrfSemanticWeight + (float)$this->rrfKeywordWeight;

        if (abs($total - 1.0) > 0.001) {
            $this->addError(
                $attribute,
                'Semantic weight and Keyword weight must add up to 1.00 (currently ' . number_format($total, 2) . ').',
            );
        }
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

        if ($resolved === null || $resolved === '') {
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
            if (!self::isValidOrigin($entry)) {
                $this->addError($attribute, 'Each origin must be like https://app.example.com (scheme, host, optional port; no path, query, fragment, or wildcard).');
                return;
            }
        }
    }

    /**
     * True when $origin is a scheme://host[:port] string safe to use in a
     * CORS-style allowlist: http(s) scheme, DNS-safe host (letters, digits,
     * dot, hyphen with no leading/trailing hyphen per label), optional port.
     *
     * Wildcards, query strings, fragments, paths, and userinfo are rejected
     * — loose matching here is a cross-origin abuse vector once the value
     * flows into SearchController::enforceOriginAllowlist().
     */
    private static function isValidOrigin(string $origin): bool
    {
        return (bool)preg_match(
            '#^https?://[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)*(:\d+)?$#i',
            $origin,
        );
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
        $token = $this->parseEnvOrNull($this->apiToken);
        if ($token === null) {
            return null;
        }
        $trimmed = trim($token);
        return $trimmed === '' ? null : $trimmed;
    }

    public function getQualifiedVectorsTable(): string
    {
        $qualified = $this->getQualifiedVectorsTableOrNull();
        if ($qualified === null) {
            throw new RuntimeException('Vectors table/schema name failed identifier validation.');
        }
        return $qualified;
    }

    /**
     * Same as {@see getQualifiedVectorsTable()} but returns null instead of
     * throwing when the schema/table name is empty or fails identifier
     * validation. Use this in read-only contexts (settings UI, status panels,
     * health checks) where an unconfigured plugin must not crash the page.
     *
     * SQL-executing callers should keep using the throwing variant — the throw
     * is a defense against unvalidated identifiers being interpolated into raw
     * SQL.
     */
    public function getQualifiedVectorsTableOrNull(): ?string
    {
        $schema = $this->vectorsSchemaName;
        $table = $this->vectorsTableName;

        if (!preg_match(self::IDENTIFIER_REGEX, $schema) || !preg_match(self::IDENTIFIER_REGEX, $table)) {
            return null;
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

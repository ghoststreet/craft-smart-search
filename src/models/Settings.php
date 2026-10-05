<?php

namespace ghoststreet\craftsmartsearch\models;

use craft\base\Model;
use craft\helpers\App;
use ghoststreet\craftsmartsearch\providers\AiProvider;
use ghoststreet\craftsmartsearch\providers\ProviderRegistry;
use ghoststreet\craftsmartsearch\SmartSearch;

/**
 * smart-search settings
 */
class Settings extends Model
{
    public const SCENARIO_CONNECTIONS = 'connections';
    public const SCENARIO_INDEXING = 'indexing';
    public const SCENARIO_SMART_SEARCH = 'smart-search';
    public const SCENARIO_AI_ANSWER = 'ai-answer';
    public const SCENARIO_LIMITS = 'limits';
    public const SCENARIO_ADVANCED = 'advanced';

    /** Which API vendor serves embeddings and AI Answer. See ProviderRegistry. */
    public string $aiProvider = 'openai';

    public ?string $openaiApiKey = null;
    public ?string $openrouterApiKey = null;
    public ?string $apiToken = null;

    public float $rrfSemanticWeight = 0.3;

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

    /** Put Smart Search behind Craft's own `search` param on the front end and GraphQL. See NativeSearch. */
    public bool $enhanceNativeSearch = true;

    public ?string $allowedOrigins = null;

    public int $rateLimitSearchPerMinute = 10;
    public int $rateLimitSearchPerHour = 30;
    public int $rateLimitAiAnswerPerMinute = 10;
    public int $rateLimitAiAnswerPerHour = 50;

    public int $aiAnswerConcurrencyGlobal = 10;

    public float $costBudgetDailyGlobal = 3.0;

    /** The embedding model used for both indexing and query embeddings. */
    public string $embeddingModel = 'text-embedding-3-small';

    /**
     * Requested from the API rather than truncated locally. Fewer dimensions is
     * directly less work for the PHP scan: cost per search is linear in this.
     * Changing it requires a reindex, since stored vectors keep their own width.
     */
    public int $dimensions = 512;

    /**
     * The widths any model is allowed to offer. A model declares its own subset; this is
     * the ceiling the plugin imposes on all of them, because the Standard engine scores
     * every stored vector in PHP and the cost of a search is linear in this.
     */
    public const DIMENSION_CHOICES = [512, 768, 1024, 1536];

    /**
     * The one table describing every CP settings tab, keyed by the slug in its URL
     * (`smart-search/settings/<slug>`). `attributes` drives `scenarios()` (mass-assignment
     * filtering) and the `on` tag on every rule below; `label` and `partial` drive the tab
     * and the fields it renders.
     *
     * Adding a tab is one entry here.
     */
    public const SCENARIOS = [
        self::SCENARIO_CONNECTIONS => [
            'label' => 'Connections',
            'attributes' => [
                'aiProvider',
                'openaiApiKey',
                'openrouterApiKey',
                'embeddingModel',
                'dimensions',
                'aiAnswerModel',
            ],
            'partial' => 'smart-search/_settings/_connections',
        ],
        self::SCENARIO_INDEXING => [
            'label' => 'Indexing',
            'attributes' => [
                'minChunkTokens', 'targetChunkTokens', 'maxChunkTokens', 'overlapTokens', 'chunkThresholdTokens',
                'embeddingCacheTtlDays',
            ],
            'partial' => 'smart-search/_settings/_indexing',
        ],
        self::SCENARIO_SMART_SEARCH => [
            'label' => 'Smart Search',
            'attributes' => [
                'enhanceNativeSearch',
                'rrfSemanticWeight',
                'minSemanticThreshold', 'maxSemanticResults',
                'excerptLength',
            ],
            'partial' => 'smart-search/_settings/_smart_search',
        ],
        self::SCENARIO_AI_ANSWER => [
            'label' => 'AI Answer',
            'attributes' => [
                'maxPromptTokens', 'aiAnswerCustomPrompt',
            ],
            'partial' => 'smart-search/_settings/_ai_answer',
        ],
        self::SCENARIO_LIMITS => [
            'label' => 'Limits',
            'attributes' => [
                'rateLimitSearchPerMinute', 'rateLimitSearchPerHour',
                'costBudgetDailyGlobal',
                'rateLimitAiAnswerPerMinute', 'rateLimitAiAnswerPerHour',
                'aiAnswerConcurrencyGlobal',
            ],
            'partial' => 'smart-search/_settings/_limits',
        ],
        self::SCENARIO_ADVANCED => [
            'label' => 'API Access',
            'attributes' => [
                'apiToken', 'allowedOrigins',
            ],
            'partial' => 'smart-search/_settings/_advanced',
        ],
    ];

    /**
     * Only the attributes Yii would otherwise label wrongly. Everything else reads fine
     * from its camel-case name.
     */
    public function attributeLabels(): array
    {
        return [
            'aiProvider' => 'Provider',
            'openaiApiKey' => 'OpenAI API key',
            'openrouterApiKey' => 'OpenRouter API key',
            'apiToken' => 'API token',
            'embeddingModel' => 'Embedding model',
            'aiAnswerModel' => 'Answer model',
        ];
    }

    public function scenarios(): array
    {
        return array_merge(
            parent::scenarios(),
            array_map(fn(array $page) => $page['attributes'], self::SCENARIOS),
        );
    }

    /**
     * Validation rules for all plugin settings, grouped by feature area.
     *
     * Each rule is tagged with `on` so it only runs under its tab's scenario
     * (and the default scenario for programmatic saves).
     */
    public function rules(): array
    {
        $connections = [self::SCENARIO_DEFAULT, self::SCENARIO_CONNECTIONS];
        $indexing = [self::SCENARIO_DEFAULT, self::SCENARIO_INDEXING];
        $smartSearch = [self::SCENARIO_DEFAULT, self::SCENARIO_SMART_SEARCH];
        $aiAnswer = [self::SCENARIO_DEFAULT, self::SCENARIO_AI_ANSWER];
        $limits = [self::SCENARIO_DEFAULT, self::SCENARIO_LIMITS];
        $advanced = [self::SCENARIO_DEFAULT, self::SCENARIO_ADVANCED];

        return [
            [['aiProvider'], 'required', 'on' => $connections],
            [['aiProvider'], 'in', 'range' => ProviderRegistry::handles(), 'on' => $connections],
            [
                ['openaiApiKey', 'openrouterApiKey'],
                'validateEnvSecret',
                'on' => $connections,
                'when' => fn(self $model, string $attribute): bool => $attribute === $model->provider()->keyAttribute(),
            ],

            // Without a width choice, the narrowest width the model returns.
            [['dimensions'], 'filter', 'filter' => fn($value) => $this->provider()->offersWidthChoice() ? $value : $this->dimensionChoices()[0], 'on' => $connections],
            [['dimensions'], 'in', 'range' => fn(self $model): array => $model->dimensionChoices(), 'on' => $connections],

            [['minChunkTokens'], 'integer', 'min' => 10, 'max' => 500, 'on' => $indexing],
            [['targetChunkTokens'], 'integer', 'min' => 100, 'max' => 1000, 'on' => $indexing],
            [['maxChunkTokens'], 'integer', 'min' => 200, 'max' => 2000, 'on' => $indexing],
            [['overlapTokens'], 'integer', 'min' => 0, 'max' => 200, 'on' => $indexing],
            [['chunkThresholdTokens'], 'integer', 'min' => 100, 'max' => 1000, 'on' => $indexing],
            [['minChunkTokens', 'targetChunkTokens', 'overlapTokens'], 'validateChunkSizing', 'on' => $indexing],

            [['embeddingCacheTtlDays'], 'integer', 'min' => 0, 'max' => 30, 'on' => $indexing],

            [['embeddingModel'], 'required', 'on' => $connections],
            [['embeddingModel'], 'in', 'range' => fn(self $model): array => array_keys($model->provider()->embeddingModels()), 'on' => $connections],

            [['enhanceNativeSearch'], 'boolean', 'on' => $smartSearch],
            [['rrfSemanticWeight'], 'number', 'min' => 0, 'max' => 1, 'on' => $smartSearch],
            [['minSemanticThreshold'], 'number', 'min' => 0, 'max' => 1, 'on' => $smartSearch],
            [['maxSemanticResults'], 'integer', 'min' => 10, 'max' => 500, 'on' => $smartSearch],

            [['excerptLength'], 'integer', 'min' => 50, 'max' => 500, 'on' => $smartSearch],

            [['rateLimitSearchPerMinute', 'rateLimitSearchPerHour'], 'integer', 'min' => 0, 'max' => 100000, 'on' => $limits],

            [['aiAnswerModel'], 'required', 'on' => $connections],
            [['aiAnswerModel'], 'in', 'range' => fn(self $model): array => array_keys($model->provider()->answerModels()), 'on' => $connections],

            [['aiAnswerCustomPrompt'], 'string', 'on' => $aiAnswer],
            [['maxPromptTokens'], 'integer', 'min' => 500, 'max' => 100000, 'on' => $aiAnswer],

            [['costBudgetDailyGlobal'], 'number', 'min' => 0, 'on' => $limits],
            [['rateLimitAiAnswerPerMinute', 'rateLimitAiAnswerPerHour'], 'integer', 'min' => 0, 'max' => 100000, 'on' => $limits],
            [['aiAnswerConcurrencyGlobal'], 'integer', 'min' => 1, 'max' => 100000, 'on' => $limits],

            [['apiToken'], 'validateEnvSecret', 'on' => $advanced],
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

    public function validateEnvSecret(string $attribute): void
    {
        $value = $this->$attribute;

        if (!is_string($value) || $value === '') {
            return;
        }

        [, $error] = self::resolveEnvSecret($value);

        if ($error !== null) {
            $this->addError($attribute, $error);
        }
    }

    /**
     * A secret that must be given as an environment variable reference, resolved.
     *
     * @return array{0: string|null, 1: string|null} the value, or the message saying why not
     */
    public static function resolveEnvSecret(string $reference): array
    {
        if (!str_starts_with($reference, '$')) {
            return [null, 'Must be an environment variable reference (e.g. $OPENAI_API_KEY). Plain-text secrets are not allowed.'];
        }

        $resolved = App::parseEnv($reference);

        if (!is_string($resolved) || $resolved === '' || $resolved === $reference) {
            return [null, 'Environment variable ' . $reference . ' is not set or is empty.'];
        }

        return [$resolved, null];
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
     * Wildcards, query strings, fragments, paths, and userinfo are rejected:
     * loose matching here is a cross-origin abuse vector once the value
     * flows into SearchController::enforceOriginAllowlist().
     */
    private static function isValidOrigin(string $origin): bool
    {
        return (bool)preg_match(
            '#^https?://[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)*(:\d+)?$#i',
            $origin,
        );
    }

    /** The provider these settings are configured for. */
    public function provider(): AiProvider
    {
        return SmartSearch::getInstance()->providers->for($this->aiProvider);
    }

    /**
     * The widths the selected embedding model will actually return. Models disagree on
     * this, so it is asked of the model rather than offered as one fixed pair: Voyage
     * takes 512 and 1024 and rejects 1536, Perplexity's small model stops at 1024.
     *
     * A model the provider lists without widths has not been measured yet, and one it
     * does not list fails the embeddingModel rule; every choice is offered for both.
     * Saving a model is what measures it.
     *
     * @return list<int>
     */
    public function dimensionChoices(): array
    {
        return ($this->provider()->embeddingModels()[$this->embeddingModel]['dimensions'] ?? []) ?: self::DIMENSION_CHOICES;
    }

    /**
     * The key for whichever provider is selected. Callers that only need to know whether
     * the plugin can reach an API at all should use this rather than a named getter.
     */
    public function getProviderApiKey(): ?string
    {
        return $this->parseEnvOrNull($this->{$this->provider()->keyAttribute()});
    }

    /** No key is a supported mode: search runs on vectors built from the site's own content. */
    public function hasProviderKey(): bool
    {
        return !empty($this->getProviderApiKey());
    }

    /** Empty values resolve to null rather than an empty string. */
    private function parseEnvOrNull(?string $value): ?string
    {
        return empty($value) ? null : App::parseEnv($value);
    }

    public function getApiToken(): ?string
    {
        $token = $this->parseEnvOrNull($this->apiToken);
        if ($token === null) {
            return null;
        }
        $trimmed = trim($token);
        return $trimmed === '' ? null : $trimmed;
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

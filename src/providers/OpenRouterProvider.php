<?php

namespace ghoststreet\craftsmartsearch\providers;

use Craft;
use ghoststreet\craftsmartsearch\helpers\CacheTag;
use ghoststreet\craftsmartsearch\helpers\Logger;
use ghoststreet\craftsmartsearch\helpers\PricingTable;
use ghoststreet\craftsmartsearch\models\Settings;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;
use Throwable;

/**
 * OpenRouter: one key and one bill in front of many vendors' models.
 *
 * The wire format is OpenAI's on both endpoints we use, so nothing here changes how a
 * request is built beyond the address, two attribution headers and the reasoning knob's
 * spelling.
 *
 * Three things are OpenRouter-shaped rather than OpenAI-shaped:
 *
 * - The key probe is /key, not /models. OpenRouter serves its model catalogue without
 *   authentication, so probing /models would call every key valid.
 * - Both model lists come from the catalogue rather than a table in this repo, because the
 *   set of models behind one OpenRouter key is far larger and moves far faster than
 *   OpenAI's. Curating it by hand means editing this file every week and still lagging.
 * - Embedding widths are measured, not read. The catalogue publishes no vector width and
 *   reports `supported_parameters: []` for every embedding model, so the only trustworthy
 *   source is the endpoint itself. See probeWidths().
 */
class OpenRouterProvider implements AiProvider
{
    private const CATALOGUE_CACHE_KEY = 'smart_search_openrouter_catalogue';

    /**
     * Catalogue `supported_parameters` an answer model must list: the JSON schema AI Answer
     * sends as `response_format`, and the `reasoning` switch chatParams() sends. The
     * non-streamed request asks OpenRouter to route only to endpoints that honour every
     * parameter, so a model missing either one has no endpoint to answer from.
     */
    private const ANSWER_PARAMETERS = ['structured_outputs', 'reasoning'];

    private const CATALOGUE_CACHE_TTL_SECONDS = 86400;

    private const WIDTHS_CACHE_PREFIX = 'smart_search_openrouter_widths_';

    private const WIDTHS_CACHE_TTL_SECONDS = 2592000;

    /**
     * Probe statuses that say nothing about the model: a bad key, no credit, a timeout or a
     * rate limit. Server errors (5xx) count too. Any other non-200 is the model refusing.
     */
    private const FAILED_STATUSES = [401, 402, 403, 408, 429];

    /**
     * Embedding models are absent from the default catalogue listing and only appear
     * under this filter, so the catalogue is built from two calls.
     */
    private const CATALOGUE_URLS = [
        'https://openrouter.ai/api/v1/models',
        'https://openrouter.ai/api/v1/models?output_modalities=embeddings',
    ];

    /**
     * The lists to offer when the catalogue cannot be reached, so an OpenRouter outage
     * leaves the settings screen usable rather than empty. An empty select would also fail
     * validation for every value, which turns a timeout into an unsaveable form.
     *
     * Every width here was measured against the live endpoint the same way probeWidths()
     * measures one now. Labels gain their price from FALLBACK_PRICES.
     */
    private const FALLBACK_EMBEDDING_MODELS = [
        'nvidia/llama-nemotron-embed-vl-1b-v2:free' => [
            'name' => 'Llama Nemotron Embed 1B. Rate limited',
            'dimensions' => [512, 768, 1024, 1536],
        ],
        'perplexity/pplx-embed-v1-0.6b' => [
            'name' => 'Perplexity Embed 0.6B. Cheapest',
            'dimensions' => [512, 768, 1024],
        ],
        'voyageai/voyage-4-lite' => [
            'name' => 'Voyage 4 Lite. Recommended',
            'dimensions' => [512, 1024],
        ],
        'perplexity/pplx-embed-v1-4b' => [
            'name' => 'Perplexity Embed 4B',
            'dimensions' => [512, 768, 1024, 1536],
        ],
        'voyageai/voyage-4' => [
            'name' => 'Voyage 4. Balanced',
            'dimensions' => [512, 1024],
        ],
        'voyageai/voyage-4-large' => [
            'name' => 'Voyage 4 Large. Highest accuracy',
            'dimensions' => [512, 1024],
        ],
        'google/gemini-embedding-2' => [
            'name' => 'Gemini Embedding 2',
            'dimensions' => [512, 768, 1024, 1536],
        ],
    ];

    private const FALLBACK_ANSWER_MODELS = [
        'deepseek/deepseek-v4-flash' => 'DeepSeek V4 Flash. Fastest, cheapest',
        'google/gemini-3.1-flash-lite' => 'Gemini 3.1 Flash Lite. Balanced',
        'anthropic/claude-haiku-4.5' => 'Claude Haiku 4.5. Strong writing',
        'anthropic/claude-sonnet-5' => 'Claude Sonnet 5. Highest quality, slowest',
    ];

    /**
     * Floor prices, USD per 1M tokens, for the fallback models above.
     *
     * The live catalogue is authoritative; this exists so that a failed fetch cannot
     * report every search as free. Cost zero would leave costBudgetDailyGlobal unable to
     * ever trip, which turns a stale price into an unbounded spend.
     */
    private const FALLBACK_PRICES = [
        'nvidia/llama-nemotron-embed-vl-1b-v2:free' => ['input' => 0.0, 'output' => 0.0],
        'perplexity/pplx-embed-v1-0.6b' => ['input' => 0.004, 'output' => 0.0],
        'voyageai/voyage-4-lite' => ['input' => 0.02, 'output' => 0.0],
        'perplexity/pplx-embed-v1-4b' => ['input' => 0.03, 'output' => 0.0],
        'voyageai/voyage-4' => ['input' => 0.06, 'output' => 0.0],
        'voyageai/voyage-4-large' => ['input' => 0.12, 'output' => 0.0],
        'google/gemini-embedding-2' => ['input' => 0.20, 'output' => 0.0],

        'deepseek/deepseek-v4-flash' => ['input' => 0.09, 'output' => 0.18],
        'google/gemini-3.1-flash-lite' => ['input' => 0.25, 'output' => 1.50],
        'anthropic/claude-haiku-4.5' => ['input' => 1.00, 'output' => 5.00],
        'anthropic/claude-sonnet-5' => ['input' => 2.00, 'output' => 10.00],
    ];

    /** @var array<string, array{name: string, input: float, output: float, kind: string, answers: bool}>|null */
    private ?array $catalogue = null;

    public static function handle(): string
    {
        return 'openrouter';
    }

    public static function label(): string
    {
        return 'OpenRouter';
    }

    public function baseUri(): string
    {
        return 'openrouter.ai/api/v1';
    }

    public function keyAttribute(): string
    {
        return 'openrouterApiKey';
    }

    /**
     * Attribution headers. OpenRouter lists the site against the key's usage.
     *
     * Both values are admin-editable strings that end up in a raw header line, so line
     * breaks are stripped: a newline there would let the rest of the value be read as a
     * header of its own.
     */
    public function headers(): array
    {
        return [
            'HTTP-Referer' => $this->headerSafe(Craft::$app->getSites()->getPrimarySite()->getBaseUrl() ?? ''),
            'X-Title' => $this->headerSafe(Craft::$app->getSystemName()),
        ];
    }

    private function headerSafe(string $value): string
    {
        return trim(str_replace(["\r", "\n"], '', $value));
    }

    public function keyProbePath(): string
    {
        return '/key';
    }

    /**
     * Every embedding model OpenRouter serves whose widths have been measured.
     *
     * A model nobody has selected yet has no measured widths and is offered with an empty
     * list; saving it measures it. A model that measured as having no usable
     * width is dropped, which is how the store is kept from being sized for one width and
     * filled with another.
     */
    public function embeddingModels(): array
    {
        $out = [];

        foreach ($this->sortedByPrice('embeddings') as $id => $model) {
            $widths = $this->cachedWidths($id);

            if ($widths === []) {
                continue;
            }

            $out[$id] = [
                'label' => PricingTable::inputPriceLabel($model['name'], $model['input']),
                'dimensions' => $widths ?? [],
            ];
        }

        if ($out !== []) {
            return $out;
        }

        foreach (self::FALLBACK_EMBEDDING_MODELS as $id => $model) {
            $out[$id] = [
                'label' => PricingTable::inputPriceLabel($model['name'], self::FALLBACK_PRICES[$id]['input']),
                'dimensions' => $model['dimensions'],
            ];
        }

        return $out;
    }

    /**
     * Every text model OpenRouter serves, cheapest first. Price ordering rather than
     * vendor: the list is hundreds long and searchable, so the only useful thing the order
     * can say is what a search costs.
     *
     * A model that leaves the catalogue is not carried over the way a saved embedding
     * model is. There is no harm in the settings screen offering the first model instead:
     * a retired id has no price to check a budget against, and answering at an unknown
     * cost is the thing worth preventing.
     */
    public function answerModels(): array
    {
        $out = [];

        foreach ($this->sortedByPrice('text') as $id => $model) {
            if ($model['answers']) {
                $out[$id] = PricingTable::inputOutputPriceLabel($model['name'], $model['input'], $model['output']);
            }
        }

        if ($out !== []) {
            return $out;
        }

        foreach (self::FALLBACK_ANSWER_MODELS as $id => $name) {
            $out[$id] = PricingTable::inputOutputPriceLabel($name, self::FALLBACK_PRICES[$id]['input'], self::FALLBACK_PRICES[$id]['output']);
        }

        return $out;
    }

    /**
     * Reasoning is turned off rather than set low.
     *
     * `reasoning_effort: low` is cheap on OpenAI's small models, but the same request
     * through OpenRouter buys a full reasoning pass from whichever vendor serves it:
     * measured on a one-sentence summary, effort=low cost 11.2s and 159 output tokens on
     * deepseek-v4-flash against 2.7s and 7 tokens with reasoning off, and it overran the
     * client's 15 s bound outright. AI Answer summarises retrieved text it is already
     * given; there is nothing here for a reasoning pass to work out.
     *
     * `verbosity` is OpenAI-only and is dropped rather than sent to a model that would
     * reject it. `usage.include` asks for the cost of the call in the usage block.
     *
     * One model is served by several upstream endpoints, and not all of them honour
     * `response_format`; one that ignores it answers in free text. `require_parameters`
     * routes the structured request only to endpoints that support every parameter in it.
     */
    public function chatParams(bool $streaming): array
    {
        $params = [
            'reasoning' => ['enabled' => false],
            'usage' => ['include' => true],
        ];

        if ($streaming) {
            $params['stream_options'] = ['include_usage' => true];
        } else {
            $params['provider'] = ['require_parameters' => true];
        }

        return $params;
    }

    public function pricing(string $model): ?array
    {
        $entry = $this->catalogue()[$model] ?? null;

        if ($entry !== null) {
            return ['input' => $entry['input'], 'output' => $entry['output']];
        }

        return self::FALLBACK_PRICES[$model] ?? null;
    }

    /**
     * Measures a model before it is saved, so that validation and indexing both read a
     * width the endpoint has actually returned rather than one nobody has checked.
     */
    public function warmEmbeddingModel(string $model, string $apiKey): void
    {
        $this->probeWidths($model, $apiKey);
    }

    public function offersWidthChoice(): bool
    {
        return false;
    }

    /**
     * The widths this model verifiably honours, ascending. Empty means none it will answer
     * at is usable, so the model cannot be offered at all.
     *
     * Measured rather than read because there is nowhere to read it from: the catalogue
     * carries no width field, and the widths quoted in a model's prose description say
     * nothing about whether the `dimensions` request parameter is honoured.
     *
     * Every candidate is asked for by name, including the model's own native width, and
     * counted on the way back. That one check catches all three ways a model can be
     * unusable here:
     *
     * - it rejects the parameter outright, which is an error at index time, since
     *   EmbeddingService always sends the parameter
     * - it ignores the parameter and answers at its native width regardless
     * - it answers 1024 to a request for 512 without complaint
     *
     * The last is the dangerous one: a silent width mismatch fills the store with vectors
     * the scan cannot read.
     *
     * A width the model will only answer at when nobody asks is no use, which is why the
     * native width is confirmed the same way as the rest rather than trusted. Its only
     * privilege is being discovered, since a model that is narrower than anything on the
     * standard list would otherwise have no candidate at all.
     *
     * Nothing above the Standard engine's ceiling is offered, since it scores every stored
     * vector in PHP and the cost of a search is linear in the width.
     *
     * A failed call throws before anything is cached, so an outage or a bad key cannot
     * mark a good model unusable.
     *
     * @return list<int>
     */
    private function probeWidths(string $model, string $apiKey): array
    {
        $cached = $this->cachedWidths($model);

        if ($cached !== null) {
            return $cached;
        }

        $http = new GuzzleClient(['connect_timeout' => 3.0, 'timeout' => 20.0]);
        $ceiling = max(Settings::DIMENSION_CHOICES);
        $candidates = Settings::DIMENSION_CHOICES;
        $widths = [];

        $native = $this->probeWidth($http, $apiKey, $model, null);

        if ($native !== null && !in_array($native, $candidates, true)) {
            $candidates[] = $native;
        }

        foreach ($candidates as $width) {
            if ($width <= $ceiling && $this->probeWidth($http, $apiKey, $model, $width) === $width) {
                $widths[] = $width;
            }
        }

        sort($widths);

        Craft::$app->getCache()->set(
            self::WIDTHS_CACHE_PREFIX . md5($model),
            $widths,
            self::WIDTHS_CACHE_TTL_SECONDS,
            CacheTag::dependency(),
        );

        return $widths;
    }

    /**
     * One embedding call. Returns the width that came back, or null if the model refused
     * the request or answered without one.
     *
     * @throws GuzzleException|RuntimeException when the call itself failed
     */
    private function probeWidth(GuzzleClient $http, string $apiKey, string $model, ?int $dimensions): ?int
    {
        $body = ['model' => $model, 'input' => 'probe'];

        if ($dimensions !== null) {
            $body['dimensions'] = $dimensions;
        }

        $response = $http->post('https://' . $this->baseUri() . '/embeddings', [
            'headers' => ['Authorization' => 'Bearer ' . $apiKey] + $this->headers(),
            'json' => $body,
            'http_errors' => false,
        ]);

        $status = $response->getStatusCode();

        if ($status >= 500 || in_array($status, self::FAILED_STATUSES, true)) {
            throw new RuntimeException("OpenRouter answered HTTP {$status} while measuring {$model}.");
        }

        if ($status !== 200) {
            return null;
        }

        $embedding = json_decode((string)$response->getBody(), true)['data'][0]['embedding'] ?? null;

        return is_array($embedding) ? count($embedding) : null;
    }

    /** @return list<int>|null null when the model has never been measured */
    private function cachedWidths(string $model): ?array
    {
        $cached = Craft::$app->getCache()->get(self::WIDTHS_CACHE_PREFIX . md5($model));

        return is_array($cached) ? $cached : null;
    }

    /**
     * Catalogue entries of one kind, cheapest input first, then by name so that models
     * priced the same do not shuffle between page loads.
     *
     * @return array<string, array{name: string, input: float, output: float, kind: string, answers: bool}>
     */
    private function sortedByPrice(string $kind): array
    {
        $models = array_filter($this->catalogue(), fn(array $m): bool => $m['kind'] === $kind);

        uasort($models, fn(array $a, array $b): int => [$a['input'], $a['name']] <=> [$b['input'], $b['name']]);

        return $models;
    }

    /** @return array<string, array{name: string, input: float, output: float, kind: string, answers: bool}> */
    private function catalogue(): array
    {
        return $this->catalogue ??= $this->loadCatalogue();
    }

    /**
     * Refreshed daily, but a failed refresh keeps the last good copy for another day
     * instead of dropping it. Prices come from here, and while the daily budget is on an
     * answer model without one is refused (RateLimitService::acquire()), so an outage
     * must not forget them.
     *
     * @return array<string, array{name: string, input: float, output: float, kind: string, answers: bool}>
     */
    private function loadCatalogue(): array
    {
        $cache = Craft::$app->getCache();
        $cached = $cache->get(self::CATALOGUE_CACHE_KEY);
        $stale = is_array($cached) ? $cached['models'] : [];

        if (is_array($cached) && time() - $cached['fetchedAt'] < self::CATALOGUE_CACHE_TTL_SECONDS) {
            return $stale;
        }

        $catalogue = $this->fetchCatalogue();

        if ($catalogue === []) {
            Logger::warning($stale !== []
                ? 'OpenRouter catalogue unavailable, keeping the last good copy for another day'
                : 'OpenRouter catalogue unavailable, falling back to bundled models and prices');
            $catalogue = $stale;
        }

        if ($catalogue !== []) {
            $cache->set(self::CATALOGUE_CACHE_KEY, ['fetchedAt' => time(), 'models' => $catalogue], 0, CacheTag::dependency());
        }

        return $catalogue;
    }

    /** @return array<string, array{name: string, input: float, output: float, kind: string, answers: bool}> */
    private function fetchCatalogue(): array
    {
        $http = new GuzzleClient(['connect_timeout' => 3.0, 'timeout' => 10.0]);
        $catalogue = [];

        foreach (self::CATALOGUE_URLS as $url) {
            try {
                $decoded = json_decode((string)$http->get($url)->getBody(), true);
            } catch (Throwable $e) {
                Logger::exception($e, 'OpenRouter catalogue fetch', ['url' => $url]);
                continue;
            }

            foreach ($decoded['data'] ?? [] as $model) {
                $id = $model['id'] ?? null;

                if (!is_string($id) || !isset($model['pricing'])) {
                    continue;
                }

                $input = (float)($model['pricing']['prompt'] ?? 0) * 1_000_000;
                $output = (float)($model['pricing']['completion'] ?? 0) * 1_000_000;

                if ($input < 0 || $output < 0) {
                    continue;
                }

                $outputs = $model['architecture']['output_modalities'] ?? [];
                $kind = match (true) {
                    in_array('embeddings', $outputs, true) => 'embeddings',
                    in_array('text', $outputs, true) => 'text',
                    default => null,
                };

                if ($kind === null) {
                    continue;
                }

                $catalogue[$id] = [
                    'name' => (string)($model['name'] ?? $id),
                    'input' => $input,
                    'output' => $output,
                    'kind' => $kind,
                    'answers' => $kind === 'text'
                        && array_diff(self::ANSWER_PARAMETERS, $model['supported_parameters'] ?? []) === [],
                ];
            }
        }

        return $catalogue;
    }
}

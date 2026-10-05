<?php

namespace ghoststreet\craftsmartsearch\providers;

use ghoststreet\craftsmartsearch\helpers\PricingTable;

/**
 * OpenAI direct. The plugin's original and default provider.
 */
class OpenAiProvider implements AiProvider
{
    /** USD per 1M tokens. Update when OpenAI's prices change. */
    private const PRICES = [
        'text-embedding-3-small' => ['input' => 0.02, 'output' => 0.0],
        'text-embedding-3-large' => ['input' => 0.13, 'output' => 0.0],

        'gpt-5.4-nano' => ['input' => 0.20, 'output' => 1.25],
        'gpt-5.4-mini' => ['input' => 0.75, 'output' => 4.50],
        'gpt-5.4' => ['input' => 2.50, 'output' => 15.00],
    ];

    public static function handle(): string
    {
        return 'openai';
    }

    public static function label(): string
    {
        return 'OpenAI';
    }

    public function baseUri(): string
    {
        return 'api.openai.com/v1';
    }

    public function keyAttribute(): string
    {
        return 'openaiApiKey';
    }

    public function headers(): array
    {
        return [];
    }

    public function keyProbePath(): string
    {
        return '/models';
    }

    public function embeddingModels(): array
    {
        return [
            'text-embedding-3-small' => [
                'label' => PricingTable::inputPriceLabel('text-embedding-3-small. Recommended', self::PRICES['text-embedding-3-small']['input']),
                'dimensions' => [512, 1536],
            ],
            'text-embedding-3-large' => [
                'label' => PricingTable::inputPriceLabel('text-embedding-3-large. Higher accuracy', self::PRICES['text-embedding-3-large']['input']),
                'dimensions' => [512, 1536],
            ],
        ];
    }

    public function answerModels(): array
    {
        $names = [
            'gpt-5.4-nano' => 'GPT-5.4 Nano. Fastest, cheapest',
            'gpt-5.4-mini' => 'GPT-5.4 Mini. Balanced',
            'gpt-5.4' => 'GPT-5.4. Highest quality, slowest',
        ];

        $out = [];
        foreach ($names as $id => $name) {
            $out[$id] = PricingTable::inputOutputPriceLabel($name, self::PRICES[$id]['input'], self::PRICES[$id]['output']);
        }

        return $out;
    }

    /** The list above is a table in this repo, so every width is already known. */
    public function warmEmbeddingModel(string $model, string $apiKey): void
    {
    }

    public function offersWidthChoice(): bool
    {
        return true;
    }

    public function chatParams(bool $streaming): array
    {
        $params = [
            'reasoning_effort' => 'low',
            'verbosity' => 'low',
        ];

        if ($streaming) {
            $params['stream_options'] = ['include_usage' => true];
        }

        return $params;
    }

    public function pricing(string $model): ?array
    {
        return self::PRICES[$model] ?? null;
    }
}

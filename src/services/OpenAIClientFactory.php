<?php

namespace ghoststreet\craftsmartsearch\services;

use ghoststreet\craftsmartsearch\exceptions\EmbeddingException;
use ghoststreet\craftsmartsearch\SmartSearch;
use GuzzleHttp\Client as GuzzleClient;
use OpenAI;
use OpenAI\Client;
use yii\base\Component;

/**
 * Factory for creating and caching OpenAI client instances.
 * Ensures a single client instance is reused across all services.
 *
 * Injects a Guzzle HTTP client with explicit timeouts — the OpenAI SDK
 * defaults to none, which would let a stalled endpoint hold a Craft worker
 * indefinitely and defeat RateLimitService's concurrency caps.
 */
class OpenAIClientFactory extends Component
{
    private ?Client $client = null;

    /**
     * Get the OpenAI client instance.
     * Creates the client on first call and caches it for subsequent calls.
     *
     * @throws EmbeddingException If API key is not configured
     */
    public function getClient(): Client
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $settings = SmartSearch::getInstance()->getSettings();
        $apiKey = $settings->getOpenaiApiKey();

        if ($apiKey === null || $apiKey === '') {
            throw EmbeddingException::missingApiKey();
        }

        $this->client = $this->buildClient($apiKey);

        return $this->client;
    }

    /**
     * Build a one-off OpenAI client for the given resolved API key.
     * Used by the settings "Test API key" action so admins can validate a key
     * before saving — bypasses the cached client which reads from saved settings.
     */
    public function buildClient(string $apiKey): Client
    {
        $http = new GuzzleClient([
            'connect_timeout' => 3.0,
            'timeout' => 60.0,
            'read_timeout' => 60.0,
            'http_errors' => false,
        ]);

        return OpenAI::factory()
            ->withApiKey($apiKey)
            ->withHttpClient($http)
            ->make();
    }
}

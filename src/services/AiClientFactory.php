<?php

namespace ghoststreet\craftsmartsearch\services;

use ghoststreet\craftsmartsearch\providers\AiProvider;
use ghoststreet\craftsmartsearch\SmartSearch;
use GuzzleHttp\Client as GuzzleClient;
use OpenAI;
use OpenAI\Client;
use yii\base\Component;

/**
 * Factory for creating and caching the API client instance.
 * Ensures a single client instance is reused across all services.
 *
 * Every provider speaks the OpenAI wire format, so one SDK client serves all of them;
 * what changes per provider is the base URI and a couple of headers.
 *
 * Injects a Guzzle HTTP client with explicit timeouts; the OpenAI SDK
 * defaults to none, which would let a stalled endpoint hold a Craft worker
 * indefinitely and defeat RateLimitService's concurrency caps.
 */
class AiClientFactory extends Component
{
    private ?Client $client = null;

    /**
     * Get the API client for the configured provider.
     * Creates the client on first call and caches it for subsequent calls.
     * Callers check Settings::hasProviderKey() first.
     */
    public function getClient(): Client
    {
        $settings = SmartSearch::getInstance()->getSettings();

        return $this->client ??= $this->buildClient((string)$settings->getProviderApiKey(), $settings->provider());
    }

    /**
     * Build a client for the given resolved API key and provider.
     */
    private function buildClient(string $apiKey, AiProvider $provider): Client
    {
        $http = new GuzzleClient([
            'connect_timeout' => 3.0,
            'timeout' => 15.0,
            'read_timeout' => 60.0,
            'http_errors' => false,
        ]);

        $factory = OpenAI::factory()
            ->withApiKey($apiKey)
            ->withHttpClient($http)
            ->withBaseUri($provider->baseUri());

        foreach ($provider->headers() as $name => $value) {
            $factory = $factory->withHttpHeader($name, $value);
        }

        return $factory->make();
    }
}

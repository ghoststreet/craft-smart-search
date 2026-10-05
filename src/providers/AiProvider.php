<?php

namespace ghoststreet\craftsmartsearch\providers;

/**
 * One API vendor the plugin can talk to for embeddings and AI Answer.
 *
 * Every provider here speaks the OpenAI wire format on both endpoints, so there is one
 * client and one request shape; what a provider owns is the address it answers on, the
 * credential it wants, the models it offers and the handful of body knobs that differ.
 * Anything a provider cannot express through this interface does not belong behind it.
 *
 * Adding a provider is one line in ProviderRegistry::PROVIDERS.
 */
interface AiProvider
{
    /** Stable identifier stored in settings. Never change one in place. */
    public static function handle(): string;

    public static function label(): string;

    /** Host and version prefix, no scheme: the openai-php factory prepends https://. */
    public function baseUri(): string;

    /** The Settings property holding this provider's key. */
    public function keyAttribute(): string;

    /**
     * Headers every request carries beyond Authorization and Content-Type.
     *
     * @return array<string, string>
     */
    public function headers(): array;

    /**
     * Path, relative to baseUri, of a GET that requires the key and is cheap to call.
     * Must reject an invalid key: an unauthenticated endpoint would report every key valid.
     */
    public function keyProbePath(): string;

    /**
     * Embedding models offered in the CP, in display order.
     *
     * `dimensions` is every width the model will actually return, ascending. It is a list
     * rather than a ceiling because these are not ranges: Voyage takes 256, 512, 1024 and
     * rejects 768 and 1536 outright, so anything but an explicit set would offer widths
     * that fail at request time. A model that ignores the parameter and answers at its
     * native width regardless does not belong here at all, since the store would be sized
     * for one width and filled with another.
     *
     * Nothing above 1536: the Standard engine scores every stored vector in PHP, so the
     * cost of a search is linear in this, and wider vectors buy accuracy the engine cannot
     * afford.
     *
     * @return array<string, array{label: string, dimensions: list<int>}>
     */
    public function embeddingModels(): array;

    /**
     * Answer models offered in the CP, in display order.
     *
     * @return array<string, string> model id => label
     */
    public function answerModels(): array;

    /**
     * Make sure embeddingModels() can answer for this model before it is saved.
     *
     * A provider whose list is a table in this repo already knows every width and does
     * nothing here. A provider that builds its list from a live catalogue has to measure
     * the model, because a catalogue does not publish vector widths.
     */
    public function warmEmbeddingModel(string $model, string $apiKey): void;

    /** Whether the CP lets the user pick the vector width. */
    public function offersWidthChoice(): bool;

    /**
     * Vendor-specific chat body knobs, merged into the request.
     *
     * @return array<string, mixed>
     */
    public function chatParams(bool $streaming): array;

    /**
     * USD per 1M tokens for a model, or null when its price is unknown.
     *
     * @return array{input: float, output: float}|null
     */
    public function pricing(string $model): ?array;
}

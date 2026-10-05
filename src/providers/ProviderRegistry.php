<?php

namespace ghoststreet\craftsmartsearch\providers;

use InvalidArgumentException;
use yii\base\Component;

/**
 * Resolves the settings' provider handle to the object that answers for it.
 *
 * Unlike EngineRegistry, which picks the most capable engine the host can run, a provider
 * is an account decision nobody can probe for: only the site owner knows which vendor they
 * pay. So this is a lookup, not a choice.
 *
 * Adding a provider is one line in PROVIDERS.
 */
class ProviderRegistry extends Component
{
    /** @var list<class-string<AiProvider>> */
    private const PROVIDERS = [
        OpenAiProvider::class,
        OpenRouterProvider::class,
    ];

    /** @var array<string, AiProvider> */
    private array $memo = [];

    /**
     * @throws InvalidArgumentException for a handle no provider answers to
     */
    public function for(string $handle): AiProvider
    {
        foreach (self::PROVIDERS as $class) {
            if ($class::handle() === $handle) {
                return $this->memo[$handle] ??= new $class();
            }
        }

        throw new InvalidArgumentException("Unknown AI provider \"{$handle}\".");
    }

    /**
     * Every provider handle, in registration order.
     *
     * @return list<string>
     */
    public static function handles(): array
    {
        return array_keys(self::options());
    }

    /**
     * Handle => label, for the CP provider select.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $out = [];

        foreach (self::PROVIDERS as $class) {
            $out[$class::handle()] = $class::label();
        }

        return $out;
    }
}

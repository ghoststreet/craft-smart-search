<?php

namespace ghoststreet\craftsmartsearch\embeddings;

use LogicException;
use yii\base\Component;

/**
 * Picks what produces vectors here, best first.
 *
 * Unlike the engine registry this is not a host capability but an account decision, and
 * nothing can probe for a key. So: the provider when one is configured, the site's own content
 * when not. No setting, because the presence of a key already makes the choice.
 *
 * Adding a source is one line in SOURCES. LocalVectorSource is last because it is always
 * available and would otherwise shadow the rest.
 */
class VectorSourceRegistry extends Component
{
    /** @var list<class-string<VectorSource>> */
    private const SOURCES = [
        ProviderVectorSource::class,
        LocalVectorSource::class,
    ];

    /**
     * Never null: the local source needs no configuration. Resolved on every call, because a
     * settings save can add or remove the key mid-request and the answer must follow it.
     */
    public function active(): VectorSource
    {
        foreach (self::SOURCES as $class) {
            if ($class::isAvailable()) {
                return new $class();
            }
        }

        throw new LogicException('No vector source is available; LocalVectorSource must stay last in SOURCES.');
    }

    public function isLocal(): bool
    {
        return $this->active() instanceof LocalVectorSource;
    }
}

<?php

namespace ghoststreet\craftsmartsearch\engines;

use Craft;
use ghoststreet\craftsmartsearch\engines\local\LocalEngine;
use ghoststreet\craftsmartsearch\helpers\Logger;
use LogicException;
use Throwable;
use yii\base\Component;

/**
 * Picks the engine this host can run, most capable first.
 *
 * Capability, never a setting: a site gets the most capable engine its database can
 * run without being asked.
 *
 * Adding an engine is one line in ENGINES. Order is preference order; LocalEngine is
 * last because it supports every host and would otherwise shadow the rest.
 */
class EngineRegistry extends Component
{
    /** @var list<class-string<SearchEngine>> */
    private const ENGINES = [
        LocalEngine::class,
    ];

    private ?SearchEngine $memo = null;

    /** The engine serving this site. Never null: LocalEngine supports every host. */
    public function active(): SearchEngine
    {
        return $this->memo ??= new ($this->choose())();
    }

    /**
     * Every engine this host could run, most capable first. The console `engines` command
     * walks this; nothing else should need it.
     *
     * @return list<SearchEngine>
     */
    public function supported(): array
    {
        $db = Craft::$app->getDb();
        $out = [];

        foreach (self::ENGINES as $class) {
            if ($this->probe($class, $db)) {
                $out[] = new $class();
            }
        }

        return $out;
    }

    /** @return class-string<SearchEngine> */
    private function choose(): string
    {
        $db = Craft::$app->getDb();

        foreach (self::ENGINES as $class) {
            if ($this->probe($class, $db)) {
                return $class;
            }
        }

        throw new LogicException('No search engine supports this database; LocalEngine must stay last in ENGINES.');
    }

    /**
     * @param class-string<SearchEngine> $class
     */
    private function probe(string $class, $db): bool
    {
        try {
            return $class::supports($db);
        } catch (Throwable $e) {
            Logger::debug('Engine probe failed', ['engine' => $class, 'error' => $e->getMessage()]);
            return false;
        }
    }
}

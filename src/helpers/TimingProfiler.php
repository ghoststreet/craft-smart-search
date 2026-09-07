<?php

namespace ghoststreet\craftsmartsearch\helpers;

/**
 * Helper for profiling and timing operations.
 *
 * Consolidates duplicate timing patterns across the codebase into a single,
 * reusable method that uses the Logger for consistent output.
 *
 * Phases are also accumulated for the current request, so a caller can report the
 * breakdown rather than only leave it in the log. Every timed phase in the plugin
 * already routes through here, so nothing has to opt in.
 */
final class TimingProfiler
{
    /** @var array<array{name: string, ms: float}> in the order they completed */
    private static array $phases = [];

    /**
     * Execute a callable and log its execution time.
     *
     * @param string $operation Human-readable name of the operation being timed
     * @param callable $callback The operation to execute and time
     * @param array $context Additional context to include in the log message
     * @return mixed The return value of the callback
     */
    public static function profile(string $operation, callable $callback, array $context = []): mixed
    {
        $start = microtime(true);
        $result = $callback();
        self::record($operation, round((microtime(true) - $start) * 1000, 2), $context);

        return $result;
    }

    /**
     * Record a duration measured by the caller. For a phase whose elapsed time is
     * needed by the caller too, which profile() cannot return.
     */
    public static function record(string $operation, float $durationMs, array $context = []): void
    {
        self::$phases[] = ['name' => $operation, 'ms' => $durationMs];
        Logger::timing($operation, $durationMs, $context);
    }

    /**
     * The phases recorded so far this request, in completion order.
     *
     * Nested phases are present alongside the phase that contains them, so the values
     * overlap and do not sum to the total.
     *
     * @return array<array{name: string, ms: float}>
     */
    public static function phases(): array
    {
        return self::$phases;
    }

    /** Drop the recorded phases. Called once per request, before any work. */
    public static function reset(): void
    {
        self::$phases = [];
    }
}

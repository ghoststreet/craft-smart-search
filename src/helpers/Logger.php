<?php

namespace ghoststreet\craftsmartsearch\helpers;

use Craft;
use craft\helpers\App;
use Throwable;

/**
 * Centralized logging helper for the Smart Search plugin.
 *
 * All logs use the 'smart-search' category to ensure they go to
 * storage/logs/smart-search.log instead of being split across multiple log files.
 */
final class Logger
{
    private const CATEGORY = 'smart-search';

    /** Successful operations worth a trace (sync complete, schema initialized). */
    public static function info(string $message, array $context = []): void
    {
        Craft::info(self::formatMessage($message, $context), self::CATEGORY);
    }

    /** Recoverable issues that don't fail the request (skipped entries, soft-dep missing). */
    public static function warning(string $message, array $context = []): void
    {
        Craft::warning(self::formatMessage($message, $context), self::CATEGORY);
    }

    /** Request-failing problems. For caught Throwables prefer exception(). */
    public static function error(string $message, array $context = []): void
    {
        Craft::error(self::formatMessage($message, $context), self::CATEGORY);
    }

    /**
     * Whether SMART_SEARCH_DEBUG is on. The one switch for the plugin's diagnostics: debug
     * and timing lines in the log, per-phase timings in API responses, and the history
     * columns no screen reads (request id, models).
     */
    public static function debugMode(): bool
    {
        return App::parseBooleanEnv('$SMART_SEARCH_DEBUG') === true;
    }

    /** Verbose tracing. No-op unless SMART_SEARCH_DEBUG is on. */
    public static function debug(string $message, array $context = []): void
    {
        if (self::debugMode()) {
            Craft::info('[DEBUG] ' . self::formatMessage($message, $context), self::CATEGORY);
        }
    }

    /** Operation duration in ms, for profiling. No-op unless SMART_SEARCH_DEBUG is on. */
    public static function timing(string $operation, float $durationMs, array $context = []): void
    {
        if (!self::debugMode()) {
            return;
        }

        $contextSuffix = $context !== [] ? ', ' . self::formatMessage('', $context) : '';
        Craft::info("[TIMING] {$operation}: {$durationMs}ms{$contextSuffix}", self::CATEGORY);
    }

    /** Like error(), but appends exception class and full stack trace to context. */
    public static function exception(Throwable $e, string $operation, array $context = []): void
    {
        $context['exceptionMessage'] = $e->getMessage();
        $context['exceptionClass'] = get_class($e);
        $context['trace'] = $e->getTraceAsString();

        self::error("{$operation} failed: {$e->getMessage()}", $context);
    }

    /** Renders a message plus its context array as `message [k=v, k=v]`, on one line. */
    private static function formatMessage(string $message, array $context): string
    {
        $message = preg_replace('/\s*\R\s*/', ' ', $message);

        if ($context === []) {
            return $message;
        }

        $parts = [];
        foreach ($context as $key => $value) {
            $parts[] = $key . '=' . json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return $message . ' [' . implode(', ', $parts) . ']';
    }
}

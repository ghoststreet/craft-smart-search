<?php

namespace ghoststreet\craftaisearch\services;

use Craft;
use ghoststreet\craftaisearch\AiSearch;
use ghoststreet\craftaisearch\exceptions\RateLimitException;
use yii\base\Component;

/**
 * Per-IP request limits, RAG concurrency caps, and the daily cost budget.
 *
 * Counter mutations are serialized with Craft's Mutex component so cache reads
 * and writes are atomic regardless of the configured cache backend.
 */
class RateLimitService extends Component
{
    public const KIND_SEARCH = 'search';
    public const KIND_RAG = 'rag';

    private const WINDOW_MINUTE = 60;
    private const WINDOW_HOUR = 3600;

    private const CONCURRENCY_TTL = 120;

    private const MUTEX_WAIT_SECONDS = 2;

    private const RAG_ADMIT_MUTEX = 'mtx:rl:rag:admit';

    /** @throws RateLimitException */
    public function acquire(string $kind, string $ip): string
    {
        $settings = AiSearch::getInstance()->getSettings();

        [$perMin, $perHour] = match ($kind) {
            self::KIND_RAG => [$settings->rateLimitRagPerMinute, $settings->rateLimitRagPerHour],
            default => [$settings->rateLimitSearchPerMinute, $settings->rateLimitSearchPerHour],
        };

        $this->enforceWindow("rate:{$kind}:m:{$ip}", $perMin, self::WINDOW_MINUTE);
        $this->enforceWindow("rate:{$kind}:h:{$ip}", $perHour, self::WINDOW_HOUR);

        if ($kind !== self::KIND_RAG) {
            return '';
        }

        $this->lock(self::RAG_ADMIT_MUTEX);
        try {
            if ($this->isGlobalBudgetExhausted()) {
                return 'rag:fallback:' . $ip;
            }

            $this->incrementGaugeLocked("conc:rag:ip:{$ip}", $settings->ragConcurrencyPerIp, 'per-IP');
            try {
                $this->incrementGaugeLocked('conc:rag:global', $settings->ragConcurrencyGlobal, 'global');
            } catch (RateLimitException $e) {
                $this->decrementGaugeLocked("conc:rag:ip:{$ip}");
                throw $e;
            }
        } finally {
            $this->unlock(self::RAG_ADMIT_MUTEX);
        }

        return 'rag:ok:' . $ip;
    }

    public function release(string $token): void
    {
        if ($token === '' || !str_starts_with($token, 'rag:ok:')) {
            return;
        }

        $ip = substr($token, 7);

        $this->lock(self::RAG_ADMIT_MUTEX);
        try {
            $this->decrementGaugeLocked("conc:rag:ip:{$ip}");
            $this->decrementGaugeLocked('conc:rag:global');
        } finally {
            $this->unlock(self::RAG_ADMIT_MUTEX);
        }
    }

    public static function isFallbackToken(string $token): bool
    {
        return str_starts_with($token, 'rag:fallback:');
    }

    /**
     * Today's spend vs the global daily cap. `cap` is 0 when budgeting is disabled.
     *
     * @return array{spent: float, cap: float, ratio: float, remaining: float, etaDays: ?float}
     */
    public function getBudgetConsumption(?float $sevenDayBurn = null): array
    {
        $settings = AiSearch::getInstance()->getSettings();
        $cap = (float)$settings->costBudgetDailyGlobal;
        $spent = (float)Craft::$app->getCache()->get($this->todayBudgetKey());
        if ($spent < 0) {
            $spent = 0.0;
        }

        $ratio = $cap > 0 ? min(1.0, $spent / $cap) : 0.0;
        $remaining = max(0.0, $cap - $spent);

        $etaDays = null;
        if ($cap > 0 && $sevenDayBurn !== null && $sevenDayBurn > 0) {
            $etaDays = round($cap / $sevenDayBurn, 1);
        }

        return [
            'spent' => round($spent, 4),
            'cap' => $cap,
            'ratio' => round($ratio, 4),
            'remaining' => round($remaining, 4),
            'etaDays' => $etaDays,
        ];
    }

    public function recordCost(string $ip, float $costUsd): void
    {
        if ($costUsd <= 0) {
            return;
        }

        $key = $this->todayBudgetKey();
        $mutexKey = "mtx:rl:budget";

        $this->lock($mutexKey);
        try {
            $cache = Craft::$app->getCache();
            $current = (float)$cache->get($key);
            $cache->set($key, $current + $costUsd, $this->secondsUntilUtcMidnight() + 3600);
        } finally {
            $this->unlock($mutexKey);
        }
    }

    /**
     * True when today's global RAG spend has hit the configured cap. Callers
     * should fall back to plain hybrid search rather than failing the request.
     */
    public function isGlobalBudgetExhausted(): bool
    {
        $cap = (float)AiSearch::getInstance()->getSettings()->costBudgetDailyGlobal;
        if ($cap <= 0) {
            return false;
        }

        $spent = (float)Craft::$app->getCache()->get($this->todayBudgetKey());
        return $spent >= $cap;
    }

    /**
     * Sliding-window counter: blend the previous full window's count (weighted
     * by how far we are into the current window) with the running count of the
     * current window. Eliminates the 2× burst that fixed windows allow at the
     * boundary, and yields an honest Retry-After.
     */
    private function enforceWindow(string $keyBase, int $max, int $window): void
    {
        if ($max <= 0) {
            return;
        }

        $cache = Craft::$app->getCache();
        $now = time();
        $slot = intdiv($now, $window);
        $elapsed = $now - ($slot * $window);
        $weight = ($window - $elapsed) / $window;

        $currKey = "{$keyBase}:{$slot}";
        $prevKey = "{$keyBase}:" . ($slot - 1);
        $mutexKey = "mtx:rl:{$keyBase}";

        $this->lock($mutexKey);
        try {
            $curr = (int)$cache->get($currKey);
            $prev = (int)$cache->get($prevKey);
            $estimated = ($prev * $weight) + $curr;

            if ($estimated >= $max) {
                throw RateLimitException::tooManyRequests($this->retryAfterFor($prev, $curr, $max, $window, $elapsed));
            }

            $cache->set($currKey, $curr + 1, $window * 2);
        } finally {
            $this->unlock($mutexKey);
        }
    }

    /**
     * Seconds until the sliding estimate drops below the cap, assuming no new
     * traffic. Bounded by the remaining current window — once we cross it the
     * previous bucket disappears entirely.
     */
    private function retryAfterFor(int $prev, int $curr, int $max, int $window, int $elapsed): int
    {
        $windowRemaining = max(1, $window - $elapsed);

        if ($prev <= 0) {
            return $windowRemaining;
        }

        $slack = $max - $curr - 1;
        if ($slack < 0) {
            return $windowRemaining;
        }

        $delta = (int)ceil($window - $elapsed - ($slack * $window / $prev));
        return max(1, min($windowRemaining, $delta));
    }

    private function incrementGaugeLocked(string $key, int $max, string $scope): void
    {
        $cache = Craft::$app->getCache();
        $current = (int)$cache->get($key);

        if ($current >= $max) {
            throw RateLimitException::concurrencyExceeded($scope);
        }

        $cache->set($key, $current + 1, self::CONCURRENCY_TTL);
    }

    private function decrementGaugeLocked(string $key): void
    {
        $cache = Craft::$app->getCache();
        $current = (int)$cache->get($key);

        if ($current <= 0) {
            return;
        }

        $cache->set($key, $current - 1, self::CONCURRENCY_TTL);
    }

    private function lock(string $key): void
    {
        if (!Craft::$app->getMutex()->acquire($key, self::MUTEX_WAIT_SECONDS)) {
            throw RateLimitException::tooManyRequests(1);
        }
    }

    private function unlock(string $key): void
    {
        Craft::$app->getMutex()->release($key);
    }

    private function todayBudgetKey(): string
    {
        return 'cost:daily:global:' . gmdate('Y-m-d');
    }

    private function secondsUntilUtcMidnight(): int
    {
        return 86400 - (time() % 86400);
    }
}

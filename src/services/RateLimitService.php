<?php

namespace ghoststreet\craftsmartsearch\services;

use Craft;
use ghoststreet\craftsmartsearch\enums\SearchType;
use ghoststreet\craftsmartsearch\exceptions\RateLimitException;
use ghoststreet\craftsmartsearch\SmartSearch;
use yii\base\Component;

/**
 * Per-IP request limits, AI Answer concurrency caps, and the daily cost budget.
 *
 * Counter mutations are serialized with Craft's Mutex component so cache reads
 * and writes are atomic regardless of the configured cache backend.
 */
class RateLimitService extends Component
{
    private const WINDOW_MINUTE = 60;
    private const WINDOW_HOUR = 3600;

    /**
     * How long a AI Answer concurrency slot can be held before the gauge entry
     * expires on its own. Sized to cover the longest realistic streaming
     * response (typical generation + safety margin); if a request runs past
     * this, both the nonce and the gauge slot expire together, so the slot
     * is reclaimed cleanly without over-admission.
     */
    private const CONCURRENCY_TTL = 900;

    private const MUTEX_WAIT_SECONDS = 2;

    private const AI_ANSWER_ADMIT_MUTEX = 'mtx:rl:aians:admit';

    private const TOKEN_OK_PREFIX = 'aians:ok:';
    private const TOKEN_FALLBACK_PREFIX = 'aians:fallback:';
    private const NONCE_KEY_PREFIX = 'conc:aians:nonce:';

    /**
     * Admit a request and return an opaque token. Caller MUST pass the token
     * back to release() exactly once when the request completes.
     *
     * AI Answer ordering is deliberate:
     *   1. Budget kill-switch (no rate-limit window consumed on the fallback path
     *      — a budget-exhausted request must not also count against the IP's
     *      regular AI Answer quota).
     *   2. Per-IP sliding-window rate limits.
     *   3. Concurrency gauges + single-use nonce.
     *
     * @throws RateLimitException when a window cap or concurrency gauge is exceeded.
     */
    public function acquire(SearchType $type, string $ip): string
    {
        $settings = SmartSearch::getInstance()->getSettings();

        if (!$type->isAiAnswer()) {
            $this->enforceWindow("rate:{$type->value}:m:{$ip}", $settings->rateLimitSearchPerMinute, self::WINDOW_MINUTE);
            $this->enforceWindow("rate:{$type->value}:h:{$ip}", $settings->rateLimitSearchPerHour, self::WINDOW_HOUR);
            return '';
        }

        $this->lock(self::AI_ANSWER_ADMIT_MUTEX);
        try {
            if ($this->isGlobalBudgetExhausted()) {
                return self::TOKEN_FALLBACK_PREFIX . $ip;
            }
        } finally {
            $this->unlock(self::AI_ANSWER_ADMIT_MUTEX);
        }

        $this->enforceWindow("rate:aians:m:{$ip}", $settings->rateLimitAiAnswerPerMinute, self::WINDOW_MINUTE);
        $this->enforceWindow("rate:aians:h:{$ip}", $settings->rateLimitAiAnswerPerHour, self::WINDOW_HOUR);

        $nonce = bin2hex(random_bytes(8));

        $this->lock(self::AI_ANSWER_ADMIT_MUTEX);
        try {
            if ($this->isGlobalBudgetExhausted()) {
                return self::TOKEN_FALLBACK_PREFIX . $ip;
            }

            $this->incrementGaugeLocked("conc:aians:ip:{$ip}", $settings->aiAnswerConcurrencyPerIp, 'per-IP');
            try {
                $this->incrementGaugeLocked('conc:aians:global', $settings->aiAnswerConcurrencyGlobal, 'global');
            } catch (RateLimitException $e) {
                $this->decrementGaugeLocked("conc:aians:ip:{$ip}");
                throw $e;
            }

            Craft::$app->getCache()->set(self::NONCE_KEY_PREFIX . $nonce, $ip, self::CONCURRENCY_TTL);
        } finally {
            $this->unlock(self::AI_ANSWER_ADMIT_MUTEX);
        }

        return self::TOKEN_OK_PREFIX . $nonce . ':' . $ip;
    }

    /**
     * Release a AI Answer slot. Idempotent: the per-token nonce is consumed atomically
     * under the admit mutex, so a second release() for the same token is a no-op
     * — concurrency gauges are decremented exactly once per acquire.
     *
     * Tokens from non-AI-Answer acquires (empty string) and fallback tokens are ignored.
     */
    public function release(string $token): void
    {
        if ($token === '' || !str_starts_with($token, self::TOKEN_OK_PREFIX)) {
            return;
        }

        $rest = substr($token, strlen(self::TOKEN_OK_PREFIX));
        $colon = strpos($rest, ':');
        if ($colon === false) {
            return;
        }
        $nonce = substr($rest, 0, $colon);
        $ip = substr($rest, $colon + 1);

        $nonceKey = self::NONCE_KEY_PREFIX . $nonce;

        $this->lock(self::AI_ANSWER_ADMIT_MUTEX);
        try {
            $cache = Craft::$app->getCache();
            if ($cache->get($nonceKey) === false) {
                return;
            }
            $cache->delete($nonceKey);
            $this->decrementGaugeLocked("conc:aians:ip:{$ip}");
            $this->decrementGaugeLocked('conc:aians:global');
        } finally {
            $this->unlock(self::AI_ANSWER_ADMIT_MUTEX);
        }
    }

    public static function isFallbackToken(string $token): bool
    {
        return str_starts_with($token, self::TOKEN_FALLBACK_PREFIX);
    }

    /**
     * Today's spend vs the global daily cap. `cap` is 0 when budgeting is disabled.
     *
     * @return array{spent: float, cap: float, ratio: float, etaDays: ?float}
     */
    public function getBudgetConsumption(?float $sevenDayBurn = null): array
    {
        $settings = SmartSearch::getInstance()->getSettings();
        $cap = (float)$settings->costBudgetDailyGlobal;
        $spent = (float)Craft::$app->getCache()->get($this->todayBudgetKey());
        if ($spent < 0) {
            $spent = 0.0;
        }

        $ratio = $cap > 0 ? min(1.0, $spent / $cap) : 0.0;

        $etaDays = null;
        if ($cap > 0 && $sevenDayBurn !== null && $sevenDayBurn > 0) {
            $etaDays = round($cap / $sevenDayBurn, 1);
        }

        return [
            'spent' => round($spent, 4),
            'cap' => $cap,
            'ratio' => round($ratio, 4),
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
     * True when today's global AI Answer spend has hit the configured cap. Callers
     * should fall back to plain smart search rather than failing the request.
     */
    public function isGlobalBudgetExhausted(): bool
    {
        $cap = (float)SmartSearch::getInstance()->getSettings()->costBudgetDailyGlobal;
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

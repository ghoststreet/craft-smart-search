<?php

namespace ghoststreet\craftsmartsearch\exceptions;

class RateLimitException extends SmartSearchException
{
    public int $retryAfterSeconds = 60;

    public static function tooManyRequests(int $retryAfter): self
    {
        $e = self::build(null, ErrorCode::RATE_LIMIT_REQUESTS);
        $e->retryAfterSeconds = $retryAfter;
        return $e;
    }

    public static function concurrencyExceeded(string $scope): self
    {
        $e = self::build("Too many concurrent requests ({$scope} cap).", ErrorCode::RATE_LIMIT_CONCURRENCY);
        $e->retryAfterSeconds = 5;
        return $e;
    }
}

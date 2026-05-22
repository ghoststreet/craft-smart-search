<?php

namespace ghoststreet\craftsmartsearch\exceptions;

class RateLimitException extends SmartSearchException
{
    public int $retryAfterSeconds = 60;

    public static function tooManyRequests(int $retryAfter): self
    {
        $e = new self('Too many requests.');
        $e->errorCode = ErrorCode::RATE_LIMIT_REQUESTS;
        $e->retryAfterSeconds = $retryAfter;
        return $e;
    }

    public static function concurrencyExceeded(string $scope): self
    {
        $e = new self("Too many concurrent requests ({$scope} cap).");
        $e->errorCode = ErrorCode::RATE_LIMIT_CONCURRENCY;
        $e->retryAfterSeconds = 5;
        return $e;
    }
}

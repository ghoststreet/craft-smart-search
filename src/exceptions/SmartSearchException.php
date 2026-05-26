<?php

namespace ghoststreet\craftsmartsearch\exceptions;

use RuntimeException;
use Throwable;

/**
 * Base exception for all Smart Search plugin errors.
 * Extend this class for domain-specific exceptions.
 *
 * @phpstan-consistent-constructor
 */
abstract class SmartSearchException extends RuntimeException
{
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    protected ErrorCode $errorCode = ErrorCode::UNKNOWN;

    public function errorCode(): ErrorCode
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->errorCode->httpStatus();
    }

    protected static function build(string $message, ErrorCode $code, ?Throwable $previous = null): static
    {
        $e = new static($message, 0, $previous);
        $e->errorCode = $code;
        return $e;
    }
}

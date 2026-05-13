<?php

namespace ghoststreet\craftaisearch\exceptions;

use RuntimeException;

/**
 * Base exception for all AI Search plugin errors.
 * Extend this class for domain-specific exceptions.
 */
abstract class AiSearchException extends RuntimeException
{
    protected int $httpStatus = 500;

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}

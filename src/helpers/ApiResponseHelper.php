<?php

namespace ghoststreet\craftaisearch\helpers;

use Craft;
use craft\web\Controller;
use ghoststreet\craftaisearch\exceptions\AiSearchException;
use Throwable;
use yii\web\Response;

/**
 * Helper for creating standardized API responses.
 *
 * Consolidates duplicate error response patterns across controller endpoints
 * into reusable methods with consistent formatting.
 */
final class ApiResponseHelper
{
    /** Maximum allowed limit for search results */
    public const MAX_LIMIT = 100;
    /** Maximum allowed query length in characters */
    public const MAX_QUERY_LENGTH = 1000;

    /**
     * Create an error response array.
     *
     * @param Throwable $e The exception that occurred
     * @param string $operation Short operation label for log correlation
     * @param array $context Extra log context (e.g. requestId, query)
     * @return array{success: false, error: string, trace?: string}
     */
    public static function error(Throwable $e, string $operation = 'API error', array $context = []): array
    {
        Logger::exception($e, $operation, $context);

        $response = [
            'success' => false,
            'error' => $e->getMessage(),
        ];

        if (!empty($context['requestId'])) {
            $response['requestId'] = $context['requestId'];
        }

        if (Craft::$app->getConfig()->getGeneral()->devMode) {
            $response['trace'] = $e->getTraceAsString();
        }

        return $response;
    }

    /**
     * Build a JSON error Response with status code derived from the exception type.
     * AiSearchException subclasses provide their own httpStatus(); everything else is 500.
     */
    public static function jsonError(Controller $controller, Throwable $e, string $operation = 'API error', array $context = []): Response
    {
        $status = $e instanceof AiSearchException ? $e->httpStatus() : 500;
        return $controller->asJson(self::error($e, $operation, $context))->setStatusCode($status);
    }

    /**
     * Check if query parameter is valid and return validation error if not.
     *
     * @param string $query The query string to validate
     * @return array|null Returns validation error array if invalid, null if valid
     */
    public static function validateQuery(string $query): ?array
    {
        if (TextValidator::isEmpty($query)) {
            return ['success' => false, 'error' => 'Query parameter "q" is required'];
        }

        if (strlen($query) > self::MAX_QUERY_LENGTH) {
            return ['success' => false, 'error' => sprintf('Query exceeds maximum length of %d characters', self::MAX_QUERY_LENGTH)];
        }

        return null;
    }

    /**
     * Validate and constrain limit parameter to safe bounds.
     *
     * @param int $limit The requested limit
     * @param int $default Default limit if input is invalid
     * @return int Constrained limit between 1 and MAX_LIMIT
     */
    public static function validateLimit(int $limit, int $default = 10): int
    {
        if ($limit < 1) {
            return $default;
        }

        return min($limit, self::MAX_LIMIT);
    }
}

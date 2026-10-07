<?php

namespace ghoststreet\craftsmartsearch\helpers;

use craft\web\Controller;
use ghoststreet\craftsmartsearch\exceptions\ErrorCode;
use ghoststreet\craftsmartsearch\exceptions\RateLimitException;
use ghoststreet\craftsmartsearch\exceptions\SmartSearchException;
use ghoststreet\craftsmartsearch\services\SearchRunner;
use Throwable;
use yii\web\Response;

/**
 * Helper for creating standardized API responses.
 *
 * Strict error shape: { success: false, code, message, requestId?, retryAfter? }.
 * `message` is the exception's public message, always the plugin's own words; raw
 * exception text, provider errors and stack traces never appear in API responses;
 * they go to smart-search.log only.
 */
final class ApiResponseHelper
{
    public const MAX_QUERY_LIMIT = 100;
    public const MAX_QUERY_LENGTH = 150;

    /**
     * Build a strict error body. Always logs the exception with full trace.
     *
     * @return array{success: false, code: string, message: string, requestId?: string, retryAfter?: int}
     */
    public static function error(Throwable $e, string $operation = 'API error', array $context = []): array
    {
        $code = ErrorCode::for($e);
        $message = self::present($e, $operation, $context);

        $requestId = !empty($context['requestId']) ? (string)$context['requestId'] : null;

        $body = [
            'success' => false,
            'code' => $code->value,
            'message' => $message,
        ];

        if ($requestId !== null) {
            $body['requestId'] = $requestId;
        }

        if ($e instanceof RateLimitException) {
            $body['retryAfter'] = $e->retryAfterSeconds;
        }

        return $body;
    }

    /**
     * Log the exception and return its public message, for surfaces that render a string
     * rather than a body: CP flashes, Twig views, GraphQL errors.
     */
    public static function present(Throwable $e, string $operation, array $context = []): string
    {
        $code = ErrorCode::for($e);
        Logger::exception($e, $operation, $context + ['code' => $code->value]);

        return $e instanceof SmartSearchException ? $e->publicMessage() : $code->translated();
    }

    /**
     * Build a JSON error Response with the correct status code (and Retry-After if applicable).
     */
    public static function jsonError(Controller $controller, Throwable $e, string $operation = 'API error', array $context = []): Response
    {
        $response = $controller->asJson(self::error($e, $operation, $context))->setStatusCode(ErrorCode::for($e)->httpStatus());

        if ($e instanceof RateLimitException) {
            $response->getHeaders()->set('Retry-After', (string) $e->retryAfterSeconds);
        }

        return $response;
    }

    /**
     * The strict error body for a rejected search request.
     *
     * @return array{success: false, code: string, message: string}
     */
    public static function validationErrorBody(): array
    {
        $code = ErrorCode::SEARCH_VALIDATION_FAILED;

        return [
            'success' => false,
            'code' => $code->value,
            'message' => $code->translated(),
        ];
    }

    /**
     * Clamp a caller-supplied result limit into the safe range [1, MAX_QUERY_LIMIT].
     *
     * Non-positive values fall back to the default search limit. Never throws; this is
     * silent normalization, not validation.
     */
    public static function clampLimit(int $limit): int
    {
        return $limit < 1 ? SearchRunner::DEFAULT_SEARCH_LIMIT : min($limit, self::MAX_QUERY_LIMIT);
    }
}

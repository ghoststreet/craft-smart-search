<?php

namespace ghoststreet\craftsmartsearch\helpers;

use Craft;
use craft\web\Controller;
use ghoststreet\craftsmartsearch\exceptions\ErrorCode;
use ghoststreet\craftsmartsearch\exceptions\RateLimitException;
use ghoststreet\craftsmartsearch\exceptions\SmartSearchException;
use Throwable;
use yii\web\Response;

/**
 * Helper for creating standardized API responses.
 *
 * Strict error shape: { success: false, code, message, requestId?, retryAfter? }.
 * `message` is always the curated string from ErrorCode::message() — raw exception
 * text, HTTP client errors, and stack traces never appear in API responses; they
 * go to smart-search.log only.
 */
final class ApiResponseHelper
{
    public const MAX_QUERY_LIMIT = 100;
    public const MAX_QUERY_LENGTH = 150;

    /**
     * Build a strict error body. Always logs the exception with full trace.
     * The `message` field is the curated, user-facing string from ErrorCode::message();
     * raw exception messages are NEVER serialized to clients — they go to the log only.
     *
     * @return array{success: false, code: string, message: string, requestId?: string, retryAfter?: int}
     */
    public static function error(Throwable $e, string $operation = 'API error', array $context = []): array
    {
        $code = ErrorMapper::codeFor($e);
        Logger::exception($e, $operation, $context + ['code' => $code->value]);

        $requestId = !empty($context['requestId']) ? (string)$context['requestId'] : null;
        $message = ErrorMapper::translatedMessage($e);
        if ($requestId !== null) {
            $message .= " (Reference: {$requestId})";
        }

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
     * Build a JSON error Response with the correct status code (and Retry-After if applicable).
     */
    public static function jsonError(Controller $controller, Throwable $e, string $operation = 'API error', array $context = []): Response
    {
        $status = $e instanceof SmartSearchException ? $e->httpStatus() : 500;
        $response = $controller->asJson(self::error($e, $operation, $context))->setStatusCode($status);

        if ($e instanceof RateLimitException) {
            $response->getHeaders()->set('Retry-After', (string) $e->retryAfterSeconds);
        }

        return $response;
    }

    /**
     * Validate query parameter; return strict error body if invalid, null if valid.
     *
     * @return array{success: false, code: string, message: string}|null
     */
    public static function validateQuery(string $query): ?array
    {
        if (TextValidator::isEmpty($query) || mb_strlen($query) > self::MAX_QUERY_LENGTH) {
            return self::validationErrorBody();
        }

        return null;
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
            'message' => Craft::t('smart-search', $code->message()),
        ];
    }

    /**
     * Clamp a caller-supplied result limit into the safe range [1, MAX_QUERY_LIMIT].
     *
     * Out-of-range or non-positive values fall back to $default; $default is
     * itself clamped, so internal callers don't need to pre-sanitize. Never
     * throws — this is silent normalization, not validation.
     */
    public static function clampLimit(int $limit, int $default = 10): int
    {
        $effective = $limit < 1 ? $default : $limit;
        return min(max(1, $effective), self::MAX_QUERY_LIMIT);
    }
}

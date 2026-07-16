<?php

namespace ghoststreet\craftsmartsearch\controllers;

use craft\web\Controller;
use ghoststreet\craftsmartsearch\exceptions\ErrorCode;
use ghoststreet\craftsmartsearch\helpers\ApiResponseHelper;
use ghoststreet\craftsmartsearch\helpers\Logger;
use Throwable;
use yii\web\Response;

/**
 * Base controller for Smart Search endpoints. Generates a per-request correlation
 * ID and exposes a typed JSON error helper that always includes it.
 */
abstract class BaseApiController extends Controller
{
    protected string $requestId = '';

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requestId = bin2hex(random_bytes(8));

        return true;
    }

    protected function errorContext(array $extra = []): array
    {
        return ['requestId' => $this->requestId] + $extra;
    }

    protected function jsonError(Throwable $e, string $operation, array $context = []): Response
    {
        return ApiResponseHelper::jsonError($this, $e, $operation, $this->errorContext($context));
    }

    /**
     * Log the exception and return its curated message, for the non-JSON
     * surfaces (CP flashes, Twig views) that render a string rather than a body.
     */
    protected function presentError(Throwable $e, string $operation, array $context = []): string
    {
        $code = ErrorCode::for($e);
        Logger::exception($e, $operation, $this->errorContext($context) + ['code' => $code->value]);

        return $code->translated();
    }
}

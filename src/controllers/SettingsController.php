<?php

namespace ghoststreet\craftsmartsearch\controllers;

use Craft;
use craft\helpers\App;
use ghoststreet\craftsmartsearch\exceptions\ErrorCode;
use ghoststreet\craftsmartsearch\helpers\ApiResponseHelper;
use ghoststreet\craftsmartsearch\SmartSearch;
use Throwable;
use yii\web\Response;

class SettingsController extends BaseApiController
{
    protected array|int|bool $allowAnonymous = false;

    public function actionIndex(): Response
    {
        $this->requireAdmin();

        return $this->renderTemplate('smart-search/settings', [
            'plugin' => SmartSearch::getInstance(),
            'settings' => SmartSearch::getInstance()->getSettings(),
            'selectedSubnavItem' => 'settings',
            'insightsAvailable' => SmartSearch::getInstance()->historyService->count() > 0,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requireAdmin();
        $this->requirePostRequest();

        $plugin = SmartSearch::getInstance();
        $settings = $plugin->getSettings();
        $settings->setAttributes(Craft::$app->getRequest()->getBodyParam('settings', []));

        if (!$settings->validate()
            || !Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray())
        ) {
            return $this->asModelFailure(
                $settings,
                Craft::t('smart-search', 'Could not save settings.'),
                'settings',
            );
        }

        return $this->asModelSuccess(
            $settings,
            Craft::t('smart-search', 'Settings saved.'),
            'settings',
        );
    }

    public function actionTestDatabaseConnection(): Response
    {
        $this->requireAdmin();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $settings = SmartSearch::getInstance()->getSettings();

        $config = [
            'host' => App::parseEnv((string) $request->getBodyParam('host', '')) ?: null,
            'port' => (int) (App::parseEnv((string) $request->getBodyParam('port', '')) ?: 0),
            'database' => App::parseEnv((string) $request->getBodyParam('database', '')) ?: null,
            'user' => App::parseEnv((string) $request->getBodyParam('user', '')) ?: null,
            'password' => App::parseEnv((string) $request->getBodyParam('password', '')) ?: null,
            'sslMode' => App::parseEnv((string) $request->getBodyParam('sslMode', '')) ?: 'require',
        ];

        try {
            $db = SmartSearch::getInstance()->databaseService;
            $pdo = $db->connectWithConfig($config);

            $stmt = $pdo->prepare('SELECT 1 FROM pg_tables WHERE schemaname = :schema AND tablename = :table');
            $stmt->execute([
                ':schema' => $settings->vectorsSchemaName,
                ':table' => $settings->vectorsTableName,
            ]);

            if ($stmt->fetch() === false) {
                return $this->asJson([
                    'success' => false,
                    'code' => ErrorCode::DATABASE_TABLE_MISSING->value,
                    'requestId' => $this->requestId,
                ]);
            }

            return $this->asJson(['success' => true, 'requestId' => $this->requestId]);
        } catch (Throwable $e) {
            return ApiResponseHelper::jsonError($this, $e, 'testDatabaseConnection', $this->errorContext());
        }
    }

    public function actionTestApiKey(): Response
    {
        $this->requireAdmin();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $raw = trim((string) Craft::$app->getRequest()->getBodyParam('apiKey', ''));

        if ($raw === '') {
            return $this->asJson([
                'success' => false,
                'message' => 'Enter an API key reference (e.g. $OPENAI_API_KEY) to test.',
                'requestId' => $this->requestId,
            ])->setStatusCode(400);
        }

        if (!str_starts_with($raw, '$')) {
            return $this->asJson([
                'success' => false,
                'message' => 'Must be an environment variable reference (e.g. $OPENAI_API_KEY). Plain-text secrets are not allowed.',
                'requestId' => $this->requestId,
            ])->setStatusCode(400);
        }

        $resolved = App::parseEnv($raw);
        if ($resolved === null || $resolved === '' || $resolved === $raw) {
            return $this->asJson([
                'success' => false,
                'message' => 'Environment variable ' . $raw . ' is not set or is empty.',
                'requestId' => $this->requestId,
            ])->setStatusCode(400);
        }

        try {
            $client = SmartSearch::getInstance()->openAIClientFactory->buildClient($resolved);
            $client->models()->list();
            return $this->asJson(['success' => true, 'requestId' => $this->requestId]);
        } catch (Throwable $e) {
            return ApiResponseHelper::jsonError($this, $e, 'testApiKey', $this->errorContext());
        }
    }
}

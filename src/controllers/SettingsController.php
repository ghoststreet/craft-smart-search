<?php

namespace ghoststreet\craftsmartsearch\controllers;

use Craft;
use craft\helpers\App;
use ghoststreet\craftsmartsearch\exceptions\ErrorCode;
use ghoststreet\craftsmartsearch\helpers\ApiResponseHelper;
use ghoststreet\craftsmartsearch\models\Settings;
use ghoststreet\craftsmartsearch\SmartSearch;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\Response;

class SettingsController extends BaseApiController
{
    protected array|int|bool $allowAnonymous = false;

    /**
     * Scenario => [CP page URL, template path, page key, optional sub-page].
     * Drives entry-point redirects, post-save redirects, and on-failure renders.
     */
    private const SCENARIO_PAGES = [
        Settings::SCENARIO_CONNECTIONS_OPENAI => [
            'url' => 'smart-search/settings/connections/openai',
            'template' => 'smart-search/settings/connections/openai',
            'pageKey' => 'connections',
            'subPage' => 'openai',
        ],
        Settings::SCENARIO_CONNECTIONS_POSTGRES => [
            'url' => 'smart-search/settings/connections/postgres',
            'template' => 'smart-search/settings/connections/postgres',
            'pageKey' => 'connections',
            'subPage' => 'postgres',
        ],
        Settings::SCENARIO_INDEXING => [
            'url' => 'smart-search/settings/indexing',
            'template' => 'smart-search/settings/indexing',
            'pageKey' => 'indexing',
        ],
        Settings::SCENARIO_SMART_SEARCH => [
            'url' => 'smart-search/settings/smart-search',
            'template' => 'smart-search/settings/smart-search',
            'pageKey' => 'smart-search',
        ],
        Settings::SCENARIO_AI_ANSWER => [
            'url' => 'smart-search/settings/ai-answer',
            'template' => 'smart-search/settings/ai-answer',
            'pageKey' => 'ai-answer',
        ],
        Settings::SCENARIO_ADVANCED => [
            'url' => 'smart-search/settings/advanced',
            'template' => 'smart-search/settings/advanced',
            'pageKey' => 'advanced',
        ],
    ];

    public function actionIndex(): Response
    {
        $this->requireAdmin();
        return $this->redirect(self::SCENARIO_PAGES[Settings::SCENARIO_CONNECTIONS_POSTGRES]['url']);
    }

    public function actionConnections(): Response
    {
        $this->requireAdmin();
        return $this->redirect(self::SCENARIO_PAGES[Settings::SCENARIO_CONNECTIONS_POSTGRES]['url']);
    }

    public function actionConnectionsOpenai(): Response
    {
        return $this->renderScenario(Settings::SCENARIO_CONNECTIONS_OPENAI);
    }

    public function actionConnectionsPostgres(): Response
    {
        return $this->renderScenario(Settings::SCENARIO_CONNECTIONS_POSTGRES);
    }

    public function actionIndexing(): Response
    {
        return $this->renderScenario(Settings::SCENARIO_INDEXING);
    }

    public function actionSmartSearch(): Response
    {
        return $this->renderScenario(Settings::SCENARIO_SMART_SEARCH);
    }

    public function actionAiAnswer(): Response
    {
        return $this->renderScenario(Settings::SCENARIO_AI_ANSWER);
    }

    public function actionAdvanced(): Response
    {
        return $this->renderScenario(Settings::SCENARIO_ADVANCED);
    }

    public function actionSave(): Response
    {
        $this->requireAdmin();
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $scenario = (string)$request->getBodyParam('scenario', '');
        if (!isset(self::SCENARIO_PAGES[$scenario])) {
            throw new BadRequestHttpException('Invalid settings scenario.');
        }

        $plugin = SmartSearch::getInstance();
        $settings = $plugin->getSettings();

        $allowed = Settings::SCENARIO_ATTRIBUTES[$scenario] ?? [];
        $posted = (array)$request->getBodyParam('settings', []);
        $filtered = array_intersect_key($posted, array_flip($allowed));

        $settings->setScenario($scenario);
        $settings->setAttributes($filtered, false);

        if (!$settings->validate()
            || !Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray())
        ) {
            Craft::$app->getSession()->setError(Craft::t('smart-search', 'Could not save settings.'));
            return $this->renderScenario($scenario, $settings);
        }

        Craft::$app->getSession()->setNotice(Craft::t('smart-search', 'Settings saved.'));
        return $this->redirect(self::SCENARIO_PAGES[$scenario]['url']);
    }

    private function renderScenario(string $scenario, ?Settings $settings = null): Response
    {
        $this->requireAdmin();

        $page = self::SCENARIO_PAGES[$scenario];
        $settings ??= SmartSearch::getInstance()->getSettings();

        return $this->renderTemplate($page['template'], [
            'plugin' => SmartSearch::getInstance(),
            'settings' => $settings,
            'selectedSubnavItem' => 'settings',
            'insightsAvailable' => SmartSearch::getInstance()->historyService->count() > 0,
            'pageKey' => $page['pageKey'],
            'connectionsSubPage' => $page['subPage'] ?? null,
            'scenario' => $scenario,
        ]);
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

<?php

namespace ghoststreet\craftsmartsearch\controllers;

use Craft;
use craft\helpers\App;
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
     * Scenario => [CP page URL, fields partial, page key, optional sub-page].
     * Drives entry-point redirects, post-save redirects, and on-failure renders.
     * Every scenario renders settings/_page; only the fields partial differs.
     */
    private const SCENARIO_PAGES = [
        Settings::SCENARIO_CONNECTIONS_OPENAI => [
            'url' => 'smart-search/settings/connections/openai',
            'partial' => 'smart-search/_settings/_connections_openai',
            'pageKey' => 'connections',
            'subPage' => 'openai',
        ],
        Settings::SCENARIO_CONNECTIONS_POSTGRES => [
            'url' => 'smart-search/settings/connections/postgres',
            'partial' => 'smart-search/_settings/_connections_postgres',
            'pageKey' => 'connections',
            'subPage' => 'postgres',
        ],
        Settings::SCENARIO_INDEXING => [
            'url' => 'smart-search/settings/indexing',
            'partial' => 'smart-search/_settings/_indexing',
            'pageKey' => 'indexing',
        ],
        Settings::SCENARIO_SMART_SEARCH => [
            'url' => 'smart-search/settings/smart-search',
            'partial' => 'smart-search/_settings/_smart_search',
            'pageKey' => 'smart-search',
        ],
        Settings::SCENARIO_AI_ANSWER => [
            'url' => 'smart-search/settings/ai-answer',
            'partial' => 'smart-search/_settings/_ai_answer',
            'pageKey' => 'ai-answer',
        ],
        Settings::SCENARIO_ADVANCED => [
            'url' => 'smart-search/settings/advanced',
            'partial' => 'smart-search/_settings/_advanced',
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
        return $this->actionIndex();
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

        $dictionary = SmartSearch::getInstance()->dictionaryService;
        $needsTrgm = in_array($scenario, [
            Settings::SCENARIO_CONNECTIONS_POSTGRES,
            Settings::SCENARIO_SMART_SEARCH,
        ], true);

        return $this->renderTemplate('smart-search/settings/_page', [
            'plugin' => SmartSearch::getInstance(),
            'settings' => $settings,
            'selectedSubnavItem' => 'settings',
            'wikiUrl' => SmartSearch::WIKI_URL,
            'insightsAvailable' => SmartSearch::getInstance()->historyService->count() > 0,
            'trgmInstalled' => $needsTrgm && $dictionary->hasTrgmExtension(),
            'termCount' => $needsTrgm ? $dictionary->getTermCount() : 0,
            'partial' => $page['partial'],
            'pageKey' => $page['pageKey'],
            'pageUrl' => $page['url'],
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

        $db = SmartSearch::getInstance()->databaseService;
        $config = $db->expandPostedConfig($config);

        $schema = trim((string) $request->getBodyParam('vectorsSchemaName', '')) ?: $settings->vectorsSchemaName;
        $table = trim((string) $request->getBodyParam('vectorsTableName', '')) ?: $settings->vectorsTableName;

        try {
            $pdo = $db->connectWithConfig($config);

            $stmt = $pdo->prepare('SELECT 1 FROM pg_tables WHERE schemaname = :schema AND tablename = :table');
            $stmt->execute([
                ':schema' => $schema,
                ':table' => $table,
            ]);

            $response = ['success' => true, 'requestId' => $this->requestId];

            if ($stmt->fetch() === false) {
                $response['warning'] = sprintf(
                    'Connected, but the vectors table "%s"."%s" does not exist yet. Set up the pgvector schema before indexing.',
                    $schema,
                    $table,
                );
            }

            return $this->asJson($response);
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

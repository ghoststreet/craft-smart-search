<?php

namespace ghoststreet\craftaisearch\controllers;

use Craft;
use craft\web\Controller;
use ghoststreet\craftaisearch\AiSearch;
use ghoststreet\craftaisearch\helpers\ErrorPresenter;
use ghoststreet\craftaisearch\models\Settings;
use yii\web\Response;

/**
 * Settings controller for plugin settings management
 */
class SettingsController extends Controller
{
    protected array|int|bool $allowAnonymous = false;

    public function actionIndex(): Response
    {
        $this->requireAdmin();

        return $this->renderTemplate('ai-search/settings', [
            'plugin' => AiSearch::getInstance(),
            'settings' => AiSearch::getInstance()->getSettings(),
            'selectedSubnavItem' => 'settings',
        ]);
    }

    public function actionSaveQuickStart(): ?Response
    {
        return $this->saveTab(Settings::SCENARIO_QUICK_START);
    }

    public function actionSaveBehavior(): ?Response
    {
        return $this->saveTab(Settings::SCENARIO_BEHAVIOR);
    }

    public function actionSaveDatabase(): ?Response
    {
        return $this->saveTab(Settings::SCENARIO_DATABASE);
    }

    public function actionSaveAdvanced(): ?Response
    {
        return $this->saveTab(Settings::SCENARIO_ADVANCED);
    }

    /**
     * Persists only the attributes owned by the given tab's scenario, leaving
     * every other tab's stored values untouched. Yii's scenario filtering on
     * `setAttributes()` discards any posted keys outside the scenario's
     * attribute list, and `validate()` runs only the rules tagged for that
     * scenario — so a blank required field on another tab cannot block this
     * save.
     */
    private function saveTab(string $scenario): ?Response
    {
        $this->requireAdmin();
        $this->requirePostRequest();

        $plugin = AiSearch::getInstance();
        $settings = $plugin->getSettings();
        $settings->setScenario($scenario);
        $settings->setAttributes(Craft::$app->getRequest()->getBodyParam('settings', []));

        if (!$settings->validate()
            || !Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray())
        ) {
            return $this->asFailure(
                Craft::t('ai-search', 'Could not save settings.'),
                routeParams: ['settings' => $settings],
            );
        }

        return $this->asSuccess(Craft::t('ai-search', 'Settings saved.'));
    }

    /**
     * Test the PostgreSQL database connection.
     */
    public function actionTestDatabaseConnection(): Response
    {
        $this->requireAdmin();
        $this->requireAcceptsJson();

        try {
            $db = AiSearch::getInstance()->databaseService;
            $db->getConnection();

            if (!$db->isSchemaInitialized()) {
                $settings = AiSearch::getInstance()->getSettings();
                $table = $settings->getQualifiedVectorsTable();
                return $this->asJson([
                    'success' => false,
                    'message' => "Connected, but the vector table {$table} does not exist. Run the pgvector schema setup — see the README.",
                ]);
            }

            return $this->asJson(['success' => true, 'message' => 'Connected successfully.']);
        } catch (\Throwable $e) {
            return $this->asJson(['success' => false, 'message' => ErrorPresenter::present($e, 'testDatabaseConnection')]);
        }
    }

    /**
     * Test the OpenAI API key by making a lightweight API call.
     */
    public function actionTestApiKey(): Response
    {
        $this->requireAdmin();
        $this->requireAcceptsJson();

        try {
            $client = AiSearch::getInstance()->openAIClientFactory->getClient();
            $client->models()->list();
            return $this->asJson(['success' => true, 'message' => 'API key is valid.']);
        } catch (\Throwable $e) {
            return $this->asJson(['success' => false, 'message' => ErrorPresenter::present($e, 'testApiKey')]);
        }
    }
}

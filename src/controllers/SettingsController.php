<?php

namespace ghoststreet\craftaisearch\controllers;

use Craft;
use craft\web\Controller;
use ghoststreet\craftaisearch\AiSearch;
use ghoststreet\craftaisearch\helpers\ErrorPresenter;
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

    public function actionSave(): ?Response
    {
        $this->requireAdmin();
        $this->requirePostRequest();

        $plugin = AiSearch::getInstance();
        $settings = $plugin->getSettings();
        $settings->setAttributes(Craft::$app->getRequest()->getBodyParam('settings', []));

        if (!$settings->validate()
            || !Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray())
        ) {
            return $this->asModelFailure(
                $settings,
                Craft::t('ai-search', 'Could not save settings.'),
                'settings',
            );
        }

        return $this->asModelSuccess(
            $settings,
            Craft::t('ai-search', 'Settings saved.'),
            'settings',
        );
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

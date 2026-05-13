<?php

namespace ghoststreet\craftaisearch\controllers;

use Craft;
use craft\web\Controller;
use ghoststreet\craftaisearch\AiSearch;
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

    /**
     * Save plugin settings from the CP form submission.
     *
     * On validation failure, re-renders the form with the rejected (unsaved)
     * model so field-level errors and the user's input are preserved. Mirrors
     * craft\controllers\PluginsController::actionSavePluginSettings.
     */
    public function actionSave(): ?Response
    {
        $this->requireAdmin();
        $this->requirePostRequest();

        $settings = Craft::$app->getRequest()->getBodyParam('settings', []);
        $plugin = AiSearch::getInstance();

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings)) {
            return $this->asFailure(
                Craft::t('ai-search', 'Could not save settings.'),
                routeParams: ['settings' => $plugin->getSettings()],
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
            AiSearch::getInstance()->databaseService->getConnection();
            return $this->asJson(['success' => true, 'message' => 'Connected successfully.']);
        } catch (\Throwable $e) {
            return $this->asJson(['success' => false, 'message' => $e->getMessage()]);
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
            return $this->asJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}

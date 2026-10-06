<?php

namespace ghoststreet\craftsmartsearch\controllers;

use Craft;
use craft\elements\Entry;
use craft\models\Section_SiteSettings;
use ghoststreet\craftsmartsearch\helpers\CacheTag;
use ghoststreet\craftsmartsearch\helpers\Logger;
use ghoststreet\craftsmartsearch\models\Settings;
use ghoststreet\craftsmartsearch\providers\AiProvider;
use ghoststreet\craftsmartsearch\providers\ProviderRegistry;
use ghoststreet\craftsmartsearch\SmartSearch;
use GuzzleHttp\Client as GuzzleClient;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class SettingsController extends BaseCpController
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            Craft::$app->getSession()->setNotice(Craft::t('smart-search', 'Settings can only be changed where admin changes are allowed.'));
            $this->redirect('smart-search')->send();
            return false;
        }

        return true;
    }

    /**
     * One settings tab, by its slug. Every tab renders settings/_page; only the fields
     * partial differs. With no tab, the first one.
     */
    public function actionIndex(?string $tab = null): Response
    {
        if ($tab === null) {
            return $this->redirect('smart-search/settings/' . array_key_first(Settings::SCENARIOS));
        }

        if (!isset(Settings::SCENARIOS[$tab])) {
            throw new NotFoundHttpException('Unknown settings tab.');
        }

        if ($tab === Settings::SCENARIO_AI_ANSWER) {
            DashboardController::markStepDone(DashboardController::PROMPT_SEEN_PREFERENCE);
        }

        return $this->renderScenario($tab, SmartSearch::getInstance()->getSettings());
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $scenario = (string)$request->getBodyParam('scenario', '');
        if (!isset(Settings::SCENARIOS[$scenario])) {
            throw new BadRequestHttpException('Invalid settings scenario.');
        }

        $posted = (array)$request->getBodyParam('settings', []);
        if (isset($posted['aiProvider']) && !in_array($posted['aiProvider'], ProviderRegistry::handles(), true)) {
            throw new BadRequestHttpException('Unknown AI provider.');
        }

        $plugin = SmartSearch::getInstance();
        $settings = $plugin->getSettings();

        $before = self::embeddingIdentity($settings);
        $sectionsBefore = $settings->indexedSectionIds();

        $settings->setScenario($scenario);
        $settings->setAttributes($posted);

        if (!$this->warmEmbeddingModel($settings)
            || !Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray())
        ) {
            Craft::$app->getSession()->setError(Craft::t('smart-search', 'Could not save settings.'));
            return $this->renderScenario($scenario, $settings);
        }

        CacheTag::invalidateResults();

        if (self::embeddingIdentity($settings) !== $before) {
            $message = $this->reindexForNewEmbeddings();
        } elseif ($settings->indexedSectionIds() !== $sectionsBefore) {
            $message = Craft::t('smart-search', 'Settings saved. The index is updating for the sections you changed.');
        } else {
            $message = Craft::t('smart-search', 'Settings saved.');
        }

        Craft::$app->getSession()->setNotice($message);
        return $this->redirect('smart-search/settings/' . $scenario);
    }

    /**
     * What decides which embedding space a stored vector lives in: the provider, model and
     * dimensions, plus the vector source, which flips when a key is added or removed. Change
     * any of them and the index answers from a different space than the queries, which reads
     * as bad results rather than as an error.
     *
     * @return array{string, string, int, string}
     */
    private static function embeddingIdentity(Settings $settings): array
    {
        return [
            $settings->aiProvider,
            $settings->embeddingModel,
            $settings->dimensions,
            SmartSearch::getInstance()->vectorSource()->handle(),
        ];
    }

    /**
     * Automatic rather than a prompt: a stale index is silently wrong, so leaving it in
     * place until someone notices is the worse default.
     *
     * The reindex is the whole refresh. The embedding and ranking caches are keyed on the
     * model and dimensions, so the new space starts from empty entries of its own, and
     * nothing else (spend, rate limits, the provider catalogue) belongs to the old model.
     */
    private function reindexForNewEmbeddings(): string
    {
        SmartSearch::getInstance()->engine()->queueSync(null);

        Logger::info('Embedding configuration changed, queued a full reindex');

        return Craft::t('smart-search', 'Settings saved. Reindexing with the new model has started, and search keeps working while it runs.');
    }

    /**
     * One row per section for the Indexing tab's Sections table.
     *
     * @return list<array{uid: string, name: string, type: string, urls: string, entries: int, indexed: bool}>
     */
    private function sectionRows(Settings $settings): array
    {
        $indexed = $settings->indexedSectionIds();
        $counts = array_count_values(array_map('intval', Entry::find()
            ->section('*')
            ->site('*')
            ->unique()
            ->status(Entry::STATUS_ENABLED)
            ->select(['entries.sectionId'])
            ->column()));
        $rows = [];

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $siteSettings = $section->getSiteSettings();
            $withUrls = count(array_filter($siteSettings, static fn(Section_SiteSettings $site): bool => $site->hasUrls));

            $rows[] = [
                'uid' => (string)$section->uid,
                'name' => $section->name,
                'type' => $section->type,
                'urls' => match (true) {
                    $withUrls === 0 => 'no',
                    $withUrls === count($siteSettings) => 'yes',
                    default => 'some',
                },
                'entries' => $counts[(int)$section->id] ?? 0,
                'indexed' => in_array((int)$section->id, $indexed, true),
            ];
        }

        return $rows;
    }

    private function renderScenario(string $scenario, Settings $settings): Response
    {
        $plugin = SmartSearch::getInstance();

        return $this->renderTemplate('smart-search/settings/_page', [
            'settings' => $settings,
            'scenario' => $scenario,
            'scenarios' => Settings::SCENARIOS,
            'partial' => Settings::SCENARIOS[$scenario]['partial'],
            'defaults' => new Settings(),
            'wikiUrl' => SmartSearch::WIKI_URL,
            'insightsAvailable' => $plugin->historyService->hasAny(),
            'hasIndex' => $plugin->engine()->hasIndex(),
            'providers' => ProviderRegistry::options(),
            'providerModels' => $this->providerModels(),
            'sectionRows' => $scenario === Settings::SCENARIO_INDEXING ? $this->sectionRows($settings) : [],
        ]);
    }

    /** The provider posted from the settings form, or null when no provider answers to it. */
    private function postedProvider(): ?AiProvider
    {
        $handle = (string)Craft::$app->getRequest()->getBodyParam('provider', '');

        return in_array($handle, ProviderRegistry::handles(), true)
            ? SmartSearch::getInstance()->providers->for($handle)
            : null;
    }

    /**
     * A key posted from the settings form, resolved from its env reference.
     *
     * @return array{0: string|null, 1: string|null} the key, or the message saying why not
     */
    private function postedApiKey(): array
    {
        $raw = trim((string)Craft::$app->getRequest()->getBodyParam('apiKey', ''));

        if ($raw === '') {
            return [null, 'Enter an API key reference (e.g. $OPENAI_API_KEY) to test.'];
        }

        return Settings::resolveEnvSecret($raw);
    }

    /**
     * Measured before validation rather than after: the Dimensions range is read from the
     * model's widths, so a model nobody has measured would accept any width the browser
     * posted, and a mis-sized store is silently wrong rather than an error. Only the
     * Connections tab validates Dimensions, so no other tab's save waits on the provider.
     *
     * The key is validated first: measuring with one that fails validation would send it
     * to the provider and bury its own message under an Embedding model error.
     *
     * @return bool false, with the reason on the key or on `embeddingModel`, when the model could not be measured
     */
    private function warmEmbeddingModel(Settings $settings): bool
    {
        if ($settings->getScenario() !== Settings::SCENARIO_CONNECTIONS || $settings->embeddingModel === '') {
            return true;
        }

        if (!$settings->validate([$settings->provider()->keyAttribute()])) {
            return false;
        }

        $apiKey = $settings->getProviderApiKey();

        if ($apiKey === null) {
            return true;
        }

        try {
            $settings->provider()->warmEmbeddingModel($settings->embeddingModel, $apiKey);
        } catch (Throwable $e) {
            $settings->addError('embeddingModel', $this->presentError($e, 'warmEmbeddingModel', ['model' => $settings->embeddingModel]));
            return false;
        }

        return true;
    }

    public function actionTestApiKey(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $provider = $this->postedProvider();
        if ($provider === null) {
            return $this->badRequest(['success' => false, 'message' => 'Unknown provider.']);
        }

        [$resolved, $message] = $this->postedApiKey();
        if ($resolved === null) {
            return $this->badRequest(['success' => false, 'message' => $message]);
        }

        try {
            $http = new GuzzleClient(['connect_timeout' => 3.0, 'timeout' => 10.0]);
            $response = $http->get('https://' . $provider->baseUri() . $provider->keyProbePath(), [
                'headers' => ['Authorization' => 'Bearer ' . $resolved] + $provider->headers(),
                'http_errors' => false,
            ]);

            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                return $this->badRequest([
                    'success' => false,
                    'message' => $status === 401 || $status === 403
                        ? 'That key was rejected by ' . $provider::label() . '.'
                        : $provider::label() . ' returned HTTP ' . $status . '.',
                ]);
            }

            return $this->asJson(['success' => true, 'requestId' => $this->requestId]);
        } catch (Throwable $e) {
            return $this->jsonError($e, 'testApiKey');
        }
    }

    /**
     * Every provider's model lists and each embedding model's usable widths, so the
     * settings page can repopulate its three selects when the provider or the embedding
     * model changes without a round trip.
     *
     * @return array<string, array{embedding: array<string, string>, answer: array<string, string>, dimensions: array<string, list<int>>, offersWidthChoice: bool}>
     */
    private function providerModels(): array
    {
        $out = [];

        foreach (ProviderRegistry::handles() as $handle) {
            $provider = SmartSearch::getInstance()->providers->for($handle);
            $embedding = $provider->embeddingModels();

            $out[$handle] = [
                'embedding' => array_map(fn(array $m): string => $m['label'], $embedding),
                'answer' => $provider->answerModels(),
                'dimensions' => array_map(fn(array $m): array => $m['dimensions'], $embedding),
                'offersWidthChoice' => $provider->offersWidthChoice(),
            ];
        }

        return $out;
    }
}

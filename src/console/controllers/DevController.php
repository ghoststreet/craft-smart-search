<?php

namespace ghoststreet\craftsmartsearch\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateInterval;
use DateTime;
use ghoststreet\craftsmartsearch\migrations\Install;
use yii\console\ExitCode;

/**
 * Dev-only seed helpers. `smart-search/dev/seed-history` inserts fake search
 * rows so the Insights views can be stress-tested without spending API credits.
 */
class DevController extends Controller
{
    public $defaultAction = 'seed-history';

    /** @var int How many rows to insert */
    public $count = 50;

    /** @var bool Truncate existing history first */
    public $fresh = false;

    /** @var int Extra phantom site IDs to mix in (useful when the install has only 1 real site) */
    public $phantomSites = 2;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['count', 'fresh', 'phantomSites']);
    }

    public function actionSeedHistory(): int
    {
        $db = Craft::$app->getDb();
        $table = Install::HISTORY_TABLE;

        if ($this->fresh) {
            $this->stdout("Truncating {$table}...\n");
            $db->createCommand()->delete($table)->execute();
        }

        $sites = array_map(fn($s) => $s->id, Craft::$app->getSites()->getAllSites());
        if (!$sites) {
            $this->stderr("No sites found.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
        for ($p = 1; $p <= $this->phantomSites; $p++) {
            $sites[] = 9000 + $p;
        }

        $queries = [
            'how to install', 'pricing', 'contact support', 'features', 'docs',
            'api key', 'embeddings', 'craft cms plugin', 'rate limit', 'changelog',
            'getting started', 'webhook', 'license', 'roadmap', 'enterprise plan',
            'sso saml', 'self-hosting', 'twig template', 'graphql', 'matrix field',
            'jklhasdkjf', 'asdf', 'zzzzzz', 'foo bar baz', 'lorem ipsum dolor',
        ];
        $errorMessages = [
            'OpenAI API timeout after 30s',
            'Rate limit exceeded',
            'Invalid API key',
            'Embedding model returned 502',
        ];
        $embeddingModels = ['text-embedding-3-small', 'text-embedding-3-large'];
        $aiModels = ['gpt-4o-mini', 'gpt-4o'];

        $now = new DateTime();
        $rows = 0;

        for ($i = 0; $i < $this->count; $i++) {
            // Spread across last 60 days, weighted toward recent
            $daysAgo = (int)floor(($this->randFloat() ** 2) * 60);
            $secondsAgo = $daysAgo * 86400 + random_int(0, 86399);
            $date = (clone $now)->sub(new DateInterval('PT' . $secondsAgo . 'S'));

            $type = $this->pickWeighted(['smart' => 80, 'aiAnswer' => 20]);
            $isError = random_int(1, 100) <= 8;
            $isZero = !$isError && random_int(1, 100) <= 15;

            $query = $queries[array_rand($queries)];
            if ($isZero) {
                $query = $queries[20 + random_int(0, 4)];
            }

            $resultsCount = $isError ? 0 : ($isZero ? 0 : random_int(1, 25));
            $embeddingModel = $embeddingModels[array_rand($embeddingModels)];
            $embeddingTokens = $isError ? 0 : random_int(5, 40);

            $aiAnswerModel = null;
            $aiInput = 0;
            $aiOutput = 0;
            if ($type === 'aiAnswer' && !$isError) {
                $aiAnswerModel = $aiModels[array_rand($aiModels)];
                $aiInput = random_int(400, 2500);
                $aiOutput = random_int(100, 800);
            }

            $totalTokens = $embeddingTokens + $aiInput + $aiOutput;
            $cost = $this->estimateCost($embeddingModel, $embeddingTokens, $aiAnswerModel, $aiInput, $aiOutput);
            $duration = $isError ? random_int(50, 30000) : ($type === 'aiAnswer' ? random_int(800, 6000) : random_int(80, 900));

            $db->createCommand()->insert($table, [
                'requestId' => StringHelper::UUID(),
                'type' => $type,
                'query' => $query,
                'siteId' => $sites[array_rand($sites)],
                'resultsCount' => $resultsCount,
                'embeddingModel' => $embeddingModel,
                'aiAnswerModel' => $aiAnswerModel,
                'embeddingTokens' => $embeddingTokens,
                'aiAnswerInputTokens' => $aiInput,
                'aiAnswerOutputTokens' => $aiOutput,
                'totalTokens' => $totalTokens,
                'cost' => number_format($cost, 6, '.', ''),
                'durationMs' => $duration,
                'embeddingCached' => !$isError && random_int(1, 100) <= 25 ? 1 : 0,
                'hasError' => $isError ? 1 : 0,
                'errorMessage' => $isError ? $errorMessages[array_rand($errorMessages)] : null,
                'dateCreated' => Db::prepareDateForDb($date),
                'dateUpdated' => Db::prepareDateForDb($date),
                'uid' => StringHelper::UUID(),
            ])->execute();
            $rows++;
        }

        $this->stdout("Inserted {$rows} fake search history rows.\n");
        return ExitCode::OK;
    }

    private function pickWeighted(array $weights): string
    {
        $total = array_sum($weights);
        $r = random_int(1, $total);
        $acc = 0;
        foreach ($weights as $key => $w) {
            $acc += $w;
            if ($r <= $acc) {
                return (string)$key;
            }
        }
        return (string)array_key_first($weights);
    }

    private function randFloat(): float
    {
        return random_int(0, PHP_INT_MAX) / PHP_INT_MAX;
    }

    private function estimateCost(?string $embModel, int $embTokens, ?string $aiModel, int $aiIn, int $aiOut): float
    {
        // Rough per-1M-token rates; close enough for seed data.
        $embRate = $embModel === 'text-embedding-3-large' ? 0.13 : 0.02;
        $aiInRate = $aiModel === 'gpt-4o' ? 2.50 : 0.15;
        $aiOutRate = $aiModel === 'gpt-4o' ? 10.00 : 0.60;
        return ($embTokens * $embRate + $aiIn * $aiInRate + $aiOut * $aiOutRate) / 1_000_000;
    }
}

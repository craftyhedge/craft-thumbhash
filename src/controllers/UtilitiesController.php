<?php

namespace craftyhedge\craftthumbhash\controllers;

use Craft;
use craft\elements\Asset;
use craft\helpers\Queue as QueueHelper;
use craft\queue\QueueInterface;
use craft\web\Controller;
use craftyhedge\craftthumbhash\db\Table;
use craftyhedge\craftthumbhash\jobs\GenerateThumbhashBatch;
use craftyhedge\craftthumbhash\Plugin;
use craftyhedge\craftthumbhash\models\Settings;
use craftyhedge\craftthumbhash\records\ThumbhashRecord;
use craftyhedge\craftthumbhash\utilities\ThumbhashUtility;
use yii\base\InvalidArgumentException;
use yii\db\Expression;
use yii\queue\Queue as YiiQueue;
use yii\web\Response;

class UtilitiesController extends Controller
{
    private const RUN_CACHE_KEY_PREFIX = 'thumbhash:utility:run:';
    private const RUN_CACHE_KEY = self::RUN_CACHE_KEY_PREFIX . 'global';

    private function isSvgAsset(Asset $asset): bool
    {
        return strtolower((string)$asset->getExtension()) === 'svg';
    }

    private function runCacheKey(): string
    {
        return self::RUN_CACHE_KEY;
    }

    private function utilityAssetQuery(): \craft\elements\db\AssetQuery
    {
        $query = Asset::find()
            ->kind(Asset::KIND_IMAGE)
            ->filename(['not', '*.svg']);

        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();
        $volumes = $settings->volumes;

        if ($volumes !== null && $volumes !== '*') {
            $query->volume((array)$volumes);
        }

        return $query;
    }

    /**
     * @return array<int>
     */
    private function eligibleAssetIds(): array
    {
        $service = Plugin::getInstance()->thumbhash;
        $generateDataUrl = $service->shouldGenerateDataUrl();
        $assetIds = [];

        foreach ($this->utilityAssetQuery()->orderBy(['elements.id' => SORT_ASC])->each() as $asset) {
            if (!$asset instanceof Asset) {
                continue;
            }

            if ($this->isSvgAsset($asset)) {
                continue;
            }

            if (!$service->isAssetCurrent($asset, $generateDataUrl)) {
                $assetIds[] = (int)$asset->id;
            }
        }

        return $assetIds;
    }

    public function actionQueueGenerate(): Response
    {
        $this->requireCpRequest();
        $this->requireAcceptsJson();
        $this->requirePostRequest();
        $this->requirePermission('utility:' . ThumbhashUtility::id());

        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();
        $volumes = $settings->volumes;

        $queue = Craft::$app->getQueue();
        $existingRun = Craft::$app->getCache()->get($this->runCacheKey());
        if (
            is_array($existingRun) &&
            isset($existingRun['jobId'], $existingRun['total']) &&
            $queue instanceof QueueInterface
        ) {
            try {
                $details = $queue->getJobDetails((string)$existingRun['jobId']);
                $status = (int)($details['status'] ?? YiiQueue::STATUS_DONE);

                if ($status === YiiQueue::STATUS_WAITING || $status === YiiQueue::STATUS_RESERVED) {
                    return $this->asJson([
                        'success' => true,
                        'queued' => (int)$existingRun['total'],
                        'jobId' => (string)$existingRun['jobId'],
                        'existingRun' => true,
                    ]);
                }
            } catch (InvalidArgumentException) {
                // Existing run is stale; continue and start a new run.
            }
        }

        $assetIds = $this->eligibleAssetIds();
        $total = count($assetIds);

        if ($total === 0) {
            Craft::$app->getCache()->delete($this->runCacheKey());

            return $this->asJson([
                'success' => true,
                'queued' => 0,
                'jobId' => null,
            ]);
        }

        $jobId = QueueHelper::push(new GenerateThumbhashBatch([
            'volumes' => $volumes,
            'assetIds' => $assetIds,
        ]));

        Craft::$app->getCache()->set($this->runCacheKey(), [
            'jobId' => (string)$jobId,
            'total' => $total,
            'dateStarted' => time(),
        ]);

        return $this->asJson([
            'success' => true,
            'queued' => $total,
            'jobId' => (string)$jobId,
            'existingRun' => false,
        ]);
    }

    public function actionClearAll(): Response
    {
        $this->requireCpRequest();
        $this->requireAcceptsJson();
        $this->requirePostRequest();
        $this->requirePermission('utility:' . ThumbhashUtility::id());

        $deleted = Plugin::getInstance()->thumbhash->clearAllHashes();
        Craft::$app->getCache()->delete($this->runCacheKey());

        return $this->asJson([
            'success' => true,
            'deleted' => (int)$deleted,
        ]);
    }

    public function actionJobStatus(): Response
    {
        $this->requireCpRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('utility:' . ThumbhashUtility::id());

        $queue = Craft::$app->getQueue();

        $run = Craft::$app->getCache()->get($this->runCacheKey());
        $runTotal = 0;
        $runProcessed = 0;
        $runFailed = 0;
        $runStatus = null;
        $runState = 'idle';
        $runError = null;
        $hasRun = is_array($run) && isset($run['total'], $run['jobId']);

        if ($hasRun) {
            $runState = 'running';
            $runTotal = (int)$run['total'];
            $jobId = (string)$run['jobId'];

            try {
                if ($queue instanceof QueueInterface) {
                    $details = $queue->getJobDetails($jobId);
                    $runStatus = (int)$details['status'];
                    $progress = (int)($details['progress'] ?? 0);
                    $runError = isset($details['error']) && $details['error'] !== ''
                        ? (string)$details['error']
                        : null;
                    $runProcessed = (int)floor(($runTotal * $progress) / 100);

                    if ($runStatus === \craft\queue\Queue::STATUS_FAILED) {
                        $runFailed = 1;
                        $runProcessed = $runTotal;
                        $runState = 'failed';
                        $hasRun = false;
                    } elseif ($runStatus === YiiQueue::STATUS_DONE) {
                        $runProcessed = $runTotal;
                        $runState = 'completed';
                        $hasRun = false;
                    }
                }
            } catch (InvalidArgumentException) {
                // Job not found in queue anymore -> completed.
                $runStatus = YiiQueue::STATUS_DONE;
                $runProcessed = $runTotal;
                $runState = 'completed';
                $hasRun = false;
            }

            if ($runState === 'completed') {
                Craft::$app->getCache()->delete($this->runCacheKey());
            }
        }

        return $this->asJson([
            'success' => true,
            'run' => [
                'hasRun' => $hasRun,
                'state' => $runState,
                'status' => $runStatus,
                'total' => $runTotal,
                'processed' => $runProcessed,
                'failed' => $runFailed,
                'error' => $runError,
            ],
        ]);
    }

    public function actionGridRows(): Response
    {
        $this->requireCpRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('utility:' . ThumbhashUtility::id());

        $assets = $this->utilityAssetQuery()
            ->leftJoin(Table::THUMBHASHES . ' thumbhashes', '[[thumbhashes.assetId]] = [[elements.id]]')
            ->orderBy(new Expression("CASE WHEN [[thumbhashes.dataUrl]] IS NULL OR [[thumbhashes.dataUrl]] = '' THEN 0 ELSE 1 END"))
            ->addOrderBy(['elements.id' => SORT_ASC])
            ->all();

        $assetIds = array_map(static fn(Asset $asset) => (int)$asset->id, $assets);

        $records = [];
        if (!empty($assetIds)) {
            $records = ThumbhashRecord::find()
                ->where(['assetId' => $assetIds])
                ->indexBy('assetId')
                ->all();
        }

        $rows = [];
        foreach ($assets as $asset) {
            if ($this->isSvgAsset($asset)) {
                continue;
            }

            $record = $records[$asset->id] ?? null;

            $rows[] = [
                'assetId' => (int)$asset->id,
                'name' => (string)($asset->title ?: $asset->filename),
                'editUrl' => (string)($asset->getCpEditUrl() ?? ''),
                'dataUrl' => $record?->dataUrl,
            ];
        }

        return $this->asJson([
            'success' => true,
            'rows' => $rows,
        ]);
    }

}

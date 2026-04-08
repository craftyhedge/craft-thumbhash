<?php

namespace craftyhedge\craftthumbhash\controllers;

use Craft;
use craft\helpers\Queue as QueueHelper;
use craft\queue\QueueInterface;
use craft\web\Controller;
use craftyhedge\craftthumbhash\jobs\GenerateThumbhashBatch;
use craftyhedge\craftthumbhash\Plugin;
use craftyhedge\craftthumbhash\models\Settings;
use craftyhedge\craftthumbhash\utilities\ThumbhashUtility;
use yii\base\InvalidArgumentException;
use yii\queue\Queue as YiiQueue;
use yii\web\Response;

class UtilitiesController extends Controller
{
    private const RUN_CACHE_KEY_PREFIX = 'thumbhash:utility:run:';
    private const RUN_CACHE_KEY = self::RUN_CACHE_KEY_PREFIX . 'global';
    private const RUN_FAILURE_MESSAGE = 'Some images could not be processed. They remain eligible for a future run. Check the ThumbHash logs for details.';

    private function runCacheKey(): string
    {
        return self::RUN_CACHE_KEY;
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

        $job = new GenerateThumbhashBatch([
            'volumes' => $volumes,
        ]);

        // Count stale assets with the same query used by the batch job.
        $total = $job->staleAssetCount();

        if ($total === 0) {
            Craft::$app->getCache()->delete($this->runCacheKey());

            return $this->asJson([
                'success' => true,
                'queued' => 0,
                'jobId' => null,
            ]);
        }

        $jobId = QueueHelper::push($job);

        Craft::$app->getCache()->set($this->runCacheKey(), [
            'jobId' => (string)$jobId,
            'total' => $total,
            'dateStarted' => time(),
            'hasFailures' => false,
            'failureMessage' => null,
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
        $runHasFailures = false;
        $hasRun = is_array($run) && isset($run['total'], $run['jobId']);

        if ($hasRun) {
            $runState = 'running';
            $runTotal = (int)$run['total'];
            $jobId = (string)$run['jobId'];
            $runHasFailures = (bool)($run['hasFailures'] ?? false);
            $runError = isset($run['failureMessage']) && $run['failureMessage'] !== ''
                ? (string)$run['failureMessage']
                : null;

            try {
                if ($queue instanceof QueueInterface) {
                    $details = $queue->getJobDetails($jobId);
                    $runStatus = (int)$details['status'];
                    $progress = (int)($details['progress'] ?? 0);
                    $queueError = isset($details['error']) && $details['error'] !== ''
                        ? (string)$details['error']
                        : null;
                    $runProcessed = (int)floor(($runTotal * $progress) / 100);

                    if ($runStatus === \craft\queue\Queue::STATUS_FAILED) {
                        $runFailed = 1;
                        $runProcessed = $runTotal;
                        $runState = 'failed';
                        $runError = $queueError;
                        $hasRun = false;
                    } elseif ($runStatus === YiiQueue::STATUS_DONE) {
                        $runProcessed = $runTotal;
                        if ($runHasFailures) {
                            $runFailed = 1;
                            $runState = 'completed_with_failures';
                            $runError ??= self::RUN_FAILURE_MESSAGE;
                        } else {
                            $runState = 'completed';
                        }
                        $hasRun = false;
                    }
                }
            } catch (InvalidArgumentException) {
                // Job not found in queue anymore -> completed.
                $runStatus = YiiQueue::STATUS_DONE;
                $runProcessed = $runTotal;
                if ($runHasFailures) {
                    $runFailed = 1;
                    $runState = 'completed_with_failures';
                    $runError ??= self::RUN_FAILURE_MESSAGE;
                } else {
                    $runState = 'completed';
                }
                $hasRun = false;
            }

            if ($runState === 'completed' || $runState === 'completed_with_failures') {
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

        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();
        $service = Plugin::getInstance()->thumbhash;

        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        $mode = strtolower((string)$request->getQueryParam('mode', 'auto'));
        $sinceUpdatedAt = trim((string)$request->getQueryParam('sinceUpdatedAt', ''));
        if ($sinceUpdatedAt === '') {
            $sinceUpdatedAt = null;
        }
        $sinceAssetId = max(0, (int)$request->getQueryParam('sinceAssetId', 0));

        $rows = [];
        $hashRows = [];
        $cursorUpdatedAt = null;
        $cursorAssetId = 0;
        $delta = $sinceUpdatedAt !== null;

        if ($mode === 'hash') {
            $snapshot = $service->getUtilityHashRowsSnapshot($sinceUpdatedAt, $sinceAssetId);
            $hashRows = $snapshot['rows'];
            $cursorUpdatedAt = $snapshot['cursorUpdatedAt'];
            $cursorAssetId = $snapshot['cursorAssetId'];
            $delta = (bool)$snapshot['delta'];
        } elseif ($mode === 'png') {
            $snapshot = $service->getUtilityPngRowsSnapshot($sinceUpdatedAt, $sinceAssetId);
            $rows = $snapshot['rows'];
            $cursorUpdatedAt = $snapshot['cursorUpdatedAt'];
            $cursorAssetId = $snapshot['cursorAssetId'];
            $delta = (bool)$snapshot['delta'];
        } elseif ((bool)$settings->generateDataUrl) {
            $mode = 'png';
            $snapshot = $service->getUtilityPngRowsSnapshot($sinceUpdatedAt, $sinceAssetId);
            $rows = $snapshot['rows'];
            $cursorUpdatedAt = $snapshot['cursorUpdatedAt'];
            $cursorAssetId = $snapshot['cursorAssetId'];
            $delta = (bool)$snapshot['delta'];
        } else {
            $mode = 'hash';
            $snapshot = $service->getUtilityHashRowsSnapshot($sinceUpdatedAt, $sinceAssetId);
            $hashRows = $snapshot['rows'];
            $cursorUpdatedAt = $snapshot['cursorUpdatedAt'];
            $cursorAssetId = $snapshot['cursorAssetId'];
            $delta = (bool)$snapshot['delta'];
        }

        return $this->asJson([
            'success' => true,
            'mode' => $mode,
            'delta' => $delta,
            'cursorUpdatedAt' => $cursorUpdatedAt,
            'cursorAssetId' => $cursorAssetId,
            'rows' => $rows,
            'hashRows' => $hashRows,
        ]);
    }

}

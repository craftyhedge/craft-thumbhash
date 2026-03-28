<?php

namespace craftyhedge\craftthumbhash\jobs;

use Craft;
use craft\elements\Asset;
use craft\helpers\Queue as QueueHelper;
use craft\i18n\Translation;
use craft\queue\BaseJob;
use craftyhedge\craftthumbhash\Plugin;
use samdark\log\PsrMessage;
use yii\log\Logger;

class GenerateThumbhash extends BaseJob
{
    private const LOG_CATEGORY = 'thumbhash';
    private const RUN_CACHE_KEY = 'thumbhash:utility:run:global';
    private const RUN_FAILURE_MESSAGE = 'One or more images failed to generate thumbhashes. Check the ThumbHash logs for details.';

    public int $assetId;
    public int $transformAttempt = 0;

    public function execute($queue): void
    {
        $this->setProgress($queue, 0, Translation::prep('app', 'ThumbHash: Preparing asset {assetId}', [
            'assetId' => $this->assetId,
        ]));

        $asset = Asset::find()->id($this->assetId)->one();

        if (!$asset) {
            return;
        }

        if ($asset->kind !== Asset::KIND_IMAGE) {
            return;
        }

        $service = Plugin::getInstance()->thumbhash;
        $generateDataUrl = $service->shouldGenerateDataUrl();

        if ($service->isAssetCurrent($asset, $generateDataUrl)) {
            return;
        }

        $result = $service->generateHashPayloadWithStatus($asset, $generateDataUrl);
        $resultReason = $result['reason'] ?? null;

        if ($result['status'] === 'pending') {
            $maxAttempts = $service->transformSourceMaxAttempts();

            if ($this->transformAttempt < $maxAttempts) {
                $nextAttempt = $this->transformAttempt + 1;
                $delay = $service->transformSourceRetryDelaySeconds();

                $this->logEvent('info', 'thumbhash.transform.retry.scheduled', [
                    'assetId' => (int)$asset->id,
                    'attempt' => $nextAttempt,
                    'maxAttempts' => $maxAttempts,
                    'delay' => $delay,
                    'reason' => $resultReason ?? 'pending',
                    'generateDataUrl' => $generateDataUrl,
                ]);

                QueueHelper::push(
                    new self([
                        'assetId' => $this->assetId,
                        'transformAttempt' => $nextAttempt,
                    ]),
                    delay: $delay,
                );

                return;
            }

            $this->logEvent('warning', 'thumbhash.transform.retry.exhausted', [
                'assetId' => (int)$asset->id,
                'attempt' => $this->transformAttempt,
                'maxAttempts' => $maxAttempts,
                'reason' => $resultReason ?? 'pending',
                'generateDataUrl' => $generateDataUrl,
            ]);

            $this->logEvent('error', 'thumbhash.generate.failure', [
                'assetId' => (int)$asset->id,
                'reason' => 'transform_retry_exhausted',
                'pendingReason' => $resultReason,
                'generateDataUrl' => $generateDataUrl,
            ]);

            $this->markUtilityRunFailed();

            $this->setProgress($queue, 1, Translation::prep('app', 'ThumbHash: Completed asset {assetId}', [
                'assetId' => $this->assetId,
            ]));

            return;
        }

        $generated = $result['payload'];

        if ($generated !== null) {
            $service->saveHashForAsset($asset, $generated['hash'], $generated['dataUrl']);
        } else {
            $this->markUtilityRunFailed();
        }

        $this->setProgress($queue, 1, Translation::prep('app', 'ThumbHash: Completed asset {assetId}', [
            'assetId' => $this->assetId,
        ]));
    }

    protected function defaultDescription(): ?string
    {
        return Translation::prep('app', 'ThumbHash: Generating asset {assetId}', [
            'assetId' => $this->assetId,
        ]);
    }

    private function logEvent(string $level, string $event, array $context = []): void
    {
        $message = new PsrMessage($event, $context);

        if ($level === 'warning') {
            Craft::warning($message, self::LOG_CATEGORY);
            return;
        }

        if ($level === 'error') {
            Craft::error($message, self::LOG_CATEGORY);
            return;
        }

        if ($level === 'debug') {
            Craft::getLogger()->log($message, Logger::LEVEL_TRACE, self::LOG_CATEGORY);
            return;
        }

        Craft::info($message, self::LOG_CATEGORY);
    }

    private function markUtilityRunFailed(): void
    {
        $cache = Craft::$app->getCache();
        $run = $cache->get(self::RUN_CACHE_KEY);

        if (!is_array($run)) {
            return;
        }

        $run['hasFailures'] = true;
        $run['failureMessage'] ??= self::RUN_FAILURE_MESSAGE;

        $cache->set(self::RUN_CACHE_KEY, $run);
    }
}

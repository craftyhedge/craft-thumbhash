<?php

namespace craftyhedge\craftthumbhash\jobs;

use Craft;
use craft\elements\Asset;
use craft\helpers\Queue as QueueHelper;
use craft\queue\BaseJob;
use craftyhedge\craftthumbhash\Plugin;
use samdark\log\PsrMessage;
use yii\log\Logger;

class GenerateThumbhash extends BaseJob
{
    private const LOG_CATEGORY = 'thumbhash';

    public int $assetId;
    public int $transformAttempt = 0;

    public function execute($queue): void
    {
        $this->setProgress($queue, 0, "ThumbHash: Preparing asset {$this->assetId}");

        $asset = Asset::find()->id($this->assetId)->one();

        if (!$asset) {
            return;
        }

        if ($asset->kind !== Asset::KIND_IMAGE) {
            return;
        }

        $service = Plugin::getInstance()->thumbhash;
        $generateDataUrl = $service->shouldGenerateDataUrl();
        $useTransformSource = $service->shouldUseTransformSource();

        if ($service->isAssetCurrent($asset, $generateDataUrl)) {
            return;
        }

        $result = $service->generateHashPayloadWithStatus($asset, $generateDataUrl, $useTransformSource);

        if ($result['status'] === 'pending' && $useTransformSource) {
            $maxAttempts = $service->transformSourceMaxAttempts();

            if ($this->transformAttempt < $maxAttempts) {
                $nextAttempt = $this->transformAttempt + 1;
                $delay = $service->transformSourceRetryDelaySeconds();

                $this->logEvent('info', 'thumbhash.transform.retry.scheduled', [
                    'assetId' => (int)$asset->id,
                    'attempt' => $nextAttempt,
                    'maxAttempts' => $maxAttempts,
                    'delay' => $delay,
                    'reason' => 'url_pending',
                    'sourceMode' => 'transform',
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
                'reason' => 'url_pending',
                'sourceMode' => 'transform',
                'generateDataUrl' => $generateDataUrl,
            ]);

            $this->logEvent('warning', 'thumbhash.transform.fallback', [
                'assetId' => (int)$asset->id,
                'reason' => 'retry_exhausted',
                'sourceMode' => 'original',
                'generateDataUrl' => $generateDataUrl,
            ]);

            $result = $service->generateHashPayloadWithStatus($asset, $generateDataUrl, false);
        }

        $generated = $result['payload'];

        if ($generated !== null) {
            $service->saveHashForAsset($asset, $generated['hash'], $generated['dataUrl']);
        }

        $this->setProgress($queue, 1, "ThumbHash: Completed asset {$this->assetId}");
    }

    protected function defaultDescription(): ?string
    {
        return "ThumbHash: Generating asset {$this->assetId}";
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
}

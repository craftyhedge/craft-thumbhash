<?php

namespace craftyhedge\craftthumbhash\jobs;

use Craft;
use craft\helpers\Queue as QueueHelper;
use craft\base\Batchable;
use craft\db\QueryBatcher;
use craft\elements\Asset;
use craft\queue\BaseBatchedJob;
use craftyhedge\craftthumbhash\Plugin;
use samdark\log\PsrMessage;
use yii\log\Logger;

class GenerateThumbhashBatch extends BaseBatchedJob
{
    private const LOG_CATEGORY = 'thumbhash';

    /**
     * @var array<string>|string|null
     */
    public array|string|null $volumes = null;
    public int $scanned = 0;
    public int $skippedCurrent = 0;
    public int $generated = 0;
    public int $retried = 0;
    public int $failed = 0;
    public int $fallbackCount = 0;

    protected function before(): void
    {
        parent::before();

        $this->scanned = 0;
        $this->skippedCurrent = 0;
        $this->generated = 0;
        $this->retried = 0;
        $this->failed = 0;
        $this->fallbackCount = 0;
    }

    protected function after(): void
    {
        parent::after();

        $this->logEvent('info', 'thumbhash.batch.summary', [
            'scanned' => $this->scanned,
            'skippedCurrent' => $this->skippedCurrent,
            'generated' => $this->generated,
            'retried' => $this->retried,
            'failed' => $this->failed,
            'fallbackCount' => $this->fallbackCount,
            'volumes' => $this->volumes,
        ]);
    }

    protected function loadData(): Batchable
    {
        $query = Asset::find()
            ->kind(Asset::KIND_IMAGE)
            ->filename(['not', '*.svg'])
            ->orderBy('id ASC');

        if ($this->volumes !== null && $this->volumes !== '*') {
            $query->volume((array)$this->volumes);
        }

        return new QueryBatcher($query);
    }

    protected function processItem(mixed $item): void
    {
        if (!$item instanceof Asset) {
            return;
        }

        $this->scanned++;

        $service = Plugin::getInstance()->thumbhash;
        $generateDataUrl = $service->shouldGenerateDataUrl();
        $useTransformSource = $service->shouldUseTransformSource();

        if ($service->isAssetCurrent($item, $generateDataUrl)) {
            $this->skippedCurrent++;
            return;
        }

        $result = $service->generateHashPayloadWithStatus($item, $generateDataUrl, $useTransformSource);

        if ($result['status'] === 'pending' && $useTransformSource) {
            $this->retried++;

            QueueHelper::push(
                new GenerateThumbhash([
                    'assetId' => (int)$item->id,
                    'transformAttempt' => 1,
                ]),
                delay: $service->transformSourceRetryDelaySeconds(),
            );
            return;
        }

        if ($result['status'] === 'failed' && $useTransformSource) {
            $this->fallbackCount++;
            $result = $service->generateHashPayloadWithStatus($item, $generateDataUrl, false);
        }

        $generated = $result['payload'];

        if ($generated !== null) {
            $this->generated++;
            $service->saveHashForAsset($item, $generated['hash'], $generated['dataUrl']);
            return;
        }

        $this->failed++;
    }

    protected function defaultDescription(): ?string
    {
        return 'ThumbHash: Batch generating image placeholders';
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

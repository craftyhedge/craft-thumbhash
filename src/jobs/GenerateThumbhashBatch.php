<?php

namespace craftyhedge\craftthumbhash\jobs;

use Craft;
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
    private const RUN_CACHE_KEY = 'thumbhash:utility:run:global';
    private const RUN_FAILURE_MESSAGE = 'Some images could not be processed. They remain eligible for a future run. Check the ThumbHash logs for details.';

    /**
     * @var array<string>|string|null
     */
    public array|string|null $volumes = null;
    /**
     * @var array<int>|null
     */
    public ?array $assetIds = null;
    public int $scanned = 0;
    public int $skippedCurrent = 0;
    public int $generated = 0;
    public int $failed = 0;

    protected function before(): void
    {
        parent::before();

        $this->scanned = 0;
        $this->skippedCurrent = 0;
        $this->generated = 0;
        $this->failed = 0;
    }

    protected function after(): void
    {
        parent::after();

        $this->logEvent('info', 'thumbhash.batch.summary', [
            'scanned' => $this->scanned,
            'skippedCurrent' => $this->skippedCurrent,
            'generated' => $this->generated,
            'failed' => $this->failed,
            'volumes' => $this->volumes,
        ]);
    }

    protected function loadData(): Batchable
    {
        $query = Asset::find()
            ->kind(Asset::KIND_IMAGE)
            ->filename(['not', '*.svg'])
            ->orderBy('id ASC');

        if (is_array($this->assetIds)) {
            if ($this->assetIds === []) {
                return new QueryBatcher($query->id(0));
            }

            $query->id($this->assetIds);
        }

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

        if (strtolower($item->getExtension()) === 'svg') {
            return;
        }

        $service = Plugin::getInstance()->thumbhash;
        $generateDataUrl = $service->shouldGenerateDataUrl();

        if ($service->isAssetCurrent($item, $generateDataUrl)) {
            $this->skippedCurrent++;
            return;
        }

        $result = $service->generateHashPayloadWithStatus($item, $generateDataUrl);

        $generated = $result['payload'];

        if ($generated !== null) {
            $this->generated++;
            $service->saveHashForAsset($item, $generated['hash'], $generated['dataUrl']);
            return;
        }

        $this->failed++;
        $this->markUtilityRunFailed();
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

<?php

namespace craftyhedge\craftthumbhash\jobs;

use craft\helpers\Queue as QueueHelper;
use craft\base\Batchable;
use craft\db\QueryBatcher;
use craft\elements\Asset;
use craft\queue\BaseBatchedJob;
use craftyhedge\craftthumbhash\Plugin;

class GenerateThumbhashBatch extends BaseBatchedJob
{
    /**
     * @var array<string>|string|null
     */
    public array|string|null $volumes = null;

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

        $service = Plugin::getInstance()->thumbhash;
        $generateDataUrl = $service->shouldGenerateDataUrl();
        $useTransformSource = $service->shouldUseTransformSource();

        if ($service->isAssetCurrent($item, $generateDataUrl)) {
            return;
        }

        $result = $service->generateHashPayloadWithStatus($item, $generateDataUrl, $useTransformSource);

        if ($result['status'] === 'pending' && $useTransformSource) {
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
            $result = $service->generateHashPayloadWithStatus($item, $generateDataUrl, false);
        }

        $generated = $result['payload'];

        if ($generated !== null) {
            $service->saveHashForAsset($item, $generated['hash'], $generated['dataUrl']);
        }
    }

    protected function defaultDescription(): ?string
    {
        return 'ThumbHash: Batch generating image placeholders';
    }
}

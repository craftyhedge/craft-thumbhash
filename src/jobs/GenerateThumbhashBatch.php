<?php

namespace craftyhedge\craftthumbhash\jobs;

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

        if ($service->isAssetCurrent($item, true)) {
            return;
        }

        $hash = $service->generateHash($item);

        if ($hash !== null) {
            $dataUrl = $service->hashToDataUrl($hash);
            $service->saveHashForAsset($item, $hash, $dataUrl);
        }
    }

    protected function defaultDescription(): ?string
    {
        return 'ThumbHash: Batch generating image placeholders';
    }
}

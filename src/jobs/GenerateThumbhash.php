<?php

namespace craftyhedge\craftthumbhash\jobs;

use Craft;
use craft\elements\Asset;
use craft\queue\BaseJob;
use craftyhedge\craftthumbhash\Plugin;

class GenerateThumbhash extends BaseJob
{
    public int $assetId;

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

        if ($service->isAssetCurrent($asset, $generateDataUrl)) {
            return;
        }

        $generated = $service->generateHashPayload($asset, $generateDataUrl);

        if ($generated !== null) {
            $service->saveHashForAsset($asset, $generated['hash'], $generated['dataUrl']);
        }

        $this->setProgress($queue, 1, "ThumbHash: Completed asset {$this->assetId}");
    }

    protected function defaultDescription(): ?string
    {
        return "ThumbHash: Generating asset {$this->assetId}";
    }
}

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
        $asset = Asset::find()->id($this->assetId)->one();

        if (!$asset) {
            return;
        }

        if ($asset->kind !== Asset::KIND_IMAGE) {
            return;
        }

        $service = Plugin::getInstance()->thumbhash;
        $hash = $service->generateHash($asset);

        if ($hash !== null) {
            $service->saveHash($this->assetId, $hash);
        }
    }

    protected function defaultDescription(): ?string
    {
        return "Generating ThumbHash for asset {$this->assetId}";
    }
}

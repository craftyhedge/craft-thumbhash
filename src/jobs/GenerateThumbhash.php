<?php

namespace craftyhedge\craftthumbhash\jobs;

use craft\elements\Asset;
use craft\i18n\Translation;
use craft\queue\BaseJob;
use craftyhedge\craftthumbhash\Plugin;

class GenerateThumbhash extends BaseJob
{
    public int $assetId;

    public function execute($queue): void
    {
        $this->setProgress($queue, 0, Translation::prep('app', 'ThumbHash: Preparing asset {assetId}', [
            'assetId' => $this->assetId,
        ]));

        $asset = Asset::find()
            ->id($this->assetId)
            ->status(null)
            ->site('*')
            ->unique(true)
            ->one();

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

        $generated = $result['payload'];

        if ($generated !== null) {
            $service->saveHashForAsset($asset, $generated['hash'], $generated['dataUrl']);
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
}

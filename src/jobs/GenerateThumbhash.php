<?php

namespace craftyhedge\craftthumbhash\jobs;

use Craft;
use craft\elements\Asset;
use craft\i18n\Translation;
use craft\queue\BaseJob;
use craftyhedge\craftthumbhash\Plugin;

class GenerateThumbhash extends BaseJob
{
    private const RUN_CACHE_KEY = 'thumbhash:utility:run:global';
    private const RUN_FAILURE_MESSAGE = 'Some images could not be processed. They remain eligible for a future run. Check the ThumbHash logs for details.';

    public int $assetId;

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

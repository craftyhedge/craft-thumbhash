<?php

namespace craftyhedge\craftthumbhash\jobs;

use Craft;
use craft\elements\Asset;
use craft\helpers\Queue as QueueHelper;
use craft\queue\BaseJob;
use craftyhedge\craftthumbhash\Plugin;

class GenerateThumbhash extends BaseJob
{
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

                QueueHelper::push(
                    new self([
                        'assetId' => $this->assetId,
                        'transformAttempt' => $nextAttempt,
                    ]),
                    delay: $delay,
                );

                return;
            }

            Craft::warning(
                "ThumbHash: Transform source not ready after {$this->transformAttempt} attempts for asset {$asset->id}; falling back to direct source.",
                __METHOD__,
            );

            $result = $service->generateHashPayloadWithStatus($asset, $generateDataUrl, false);
        } elseif ($result['status'] === 'failed' && $useTransformSource) {
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
}

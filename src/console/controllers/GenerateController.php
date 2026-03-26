<?php

namespace craftyhedge\craftthumbhash\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\Asset;
use craftyhedge\craftthumbhash\Plugin;
use yii\console\ExitCode;

class GenerateController extends Controller
{
    /**
     * @var string|null Volume handle to limit generation to.
     */
    public ?string $volume = null;

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        if ($actionID === 'index') {
            $options[] = 'volume';
        }
        return $options;
    }

    /**
     * Generate thumbhashes for all image assets (or a specific volume).
     *
     * Usage:
     *   php craft thumbhash/generate
     *   php craft thumbhash/generate --volume=images
     */
    public function actionIndex(): int
    {
        $query = Asset::find()->kind(Asset::KIND_IMAGE);

        if ($this->volume) {
            $query->volume($this->volume);
        } else {
            $settings = Plugin::getInstance()->getSettings();
            $volumes = $settings->volumes;

            if ($volumes !== null && $volumes !== '*') {
                $query->volume((array) $volumes);
            }
        }

        $total = $query->count();

        if ($total === 0) {
            $this->stdout("No image assets found.\n");
            return ExitCode::OK;
        }

        $this->stdout("Generating thumbhashes for {$total} image assets...\n");

        $service = Plugin::getInstance()->thumbhash;
        $done = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($query->each() as $asset) {
            /** @var Asset $asset */
            $done++;

            $extension = strtolower($asset->getExtension());
            if ($extension === 'svg') {
                $skipped++;
                continue;
            }

            $hash = $service->generateHash($asset);

            if ($hash !== null) {
                $dataUrl = $service->hashToDataUrl($hash);
                $service->saveHash($asset->id, $hash, $dataUrl);
                $this->stdout("  [{$done}/{$total}] #{$asset->id} {$asset->filename} ✓\n");
            } else {
                $errors++;
                $this->stderr("  [{$done}/{$total}] #{$asset->id} {$asset->filename} — failed\n");
            }
        }

        $generated = $done - $skipped - $errors;
        $this->stdout("\nDone. Generated: {$generated}, Skipped: {$skipped}, Errors: {$errors}\n");

        return ExitCode::OK;
    }
}

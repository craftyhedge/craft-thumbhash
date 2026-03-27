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

    /**
     * @var bool Require explicit confirmation for destructive actions.
     */
    public bool $yes = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        if ($actionID === 'index') {
            $options[] = 'volume';
        }
        if ($actionID === 'clear' || $actionID === 'clear-data-urls') {
            $options[] = 'yes';
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
        $generateDataUrl = $service->shouldGenerateDataUrl();
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

            if ($service->isAssetCurrent($asset, $generateDataUrl)) {
                $skipped++;
                continue;
            }

            $generated = $service->generateHashPayload($asset, $generateDataUrl);

            if ($generated !== null) {
                $service->saveHashForAsset($asset, $generated['hash'], $generated['dataUrl']);
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

    /**
     * Clear all stored thumbhash records.
     *
     * Usage:
     *   php craft thumbhash/generate/clear --yes=1
     */
    public function actionClear(): int
    {
        if (!$this->yes) {
            $this->stderr("Refusing to clear thumbhash records without --yes=1.\n");
            return ExitCode::USAGE;
        }

        $deleted = Plugin::getInstance()->thumbhash->clearAllHashes();
        $this->stdout("Cleared {$deleted} thumbhash records.\n");

        return ExitCode::OK;
    }

    /**
     * Clear only stored PNG data URLs while keeping thumbhash strings.
     *
     * Usage:
     *   php craft thumbhash/generate/clear-data-urls --yes=1
     */
    public function actionClearDataUrls(): int
    {
        if (!$this->yes) {
            $this->stderr("Refusing to clear PNG data URLs without --yes=1.\n");
            return ExitCode::USAGE;
        }

        $updated = Plugin::getInstance()->thumbhash->clearAllDataUrls();
        $this->stdout("Cleared PNG data URLs for {$updated} thumbhash records.\n");

        return ExitCode::OK;
    }
}

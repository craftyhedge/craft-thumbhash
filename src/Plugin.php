<?php

namespace craftyhedge\craftthumbhash;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\elements\Asset;
use craft\events\ModelEvent;
use craft\events\ReplaceAssetEvent;
use craft\services\Assets;
use craftyhedge\craftthumbhash\jobs\GenerateThumbhash;
use craftyhedge\craftthumbhash\models\Settings;
use craftyhedge\craftthumbhash\services\ThumbhashService;
use craftyhedge\craftthumbhash\twig\Extension;
use yii\base\Event;

/**
 * @property ThumbhashService $thumbhash
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';

    public static function config(): array
    {
        return [
            'components' => [
                'thumbhash' => ThumbhashService::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        if (Craft::$app->getRequest()->getIsSiteRequest()) {
            Craft::$app->getView()->registerTwigExtension(new Extension());
        }

        $this->registerEventListeners();
    }

    private function registerEventListeners(): void
    {
        // Generate thumbhash when a new image asset is created
        Event::on(
            Asset::class,
            Element::EVENT_AFTER_SAVE,
            function (ModelEvent $event) {
                if (!$event->isNew) {
                    return;
                }

                /** @var Asset $asset */
                $asset = $event->sender;

                $this->pushThumbhashJob($asset);
            },
        );

        // Regenerate thumbhash when an existing asset's file is replaced
        Event::on(
            Assets::class,
            Assets::EVENT_AFTER_REPLACE_ASSET,
            function (ReplaceAssetEvent $event) {
                $this->pushThumbhashJob($event->asset);
            },
        );

        // Delete thumbhash when an asset is soft-deleted (FK CASCADE only covers hard deletes during GC)
        Event::on(
            Asset::class,
            Element::EVENT_AFTER_DELETE,
            function (Event $event) {
                /** @var Asset $asset */
                $asset = $event->sender;

                $this->thumbhash->deleteHash($asset->id);
            },
        );
    }

    private function pushThumbhashJob(Asset $asset): void
    {
        if ($asset->kind !== Asset::KIND_IMAGE) {
            return;
        }

        if (strtolower($asset->getExtension()) === 'svg') {
            return;
        }

        if (!$this->isVolumeAllowed($asset)) {
            return;
        }

        Craft::$app->getQueue()->push(new GenerateThumbhash([
            'assetId' => $asset->id,
        ]));
    }

    public function isVolumeAllowed(Asset $asset): bool
    {
        /** @var Settings $settings */
        $settings = $this->getSettings();
        $volumes = $settings->volumes;

        if ($volumes === null || $volumes === '*') {
            return true;
        }

        $volume = $asset->getVolume();

        return in_array($volume->handle, (array) $volumes, true);
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }
}

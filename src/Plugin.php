<?php

namespace craftyhedge\craftthumbhash;

use Craft;
use craft\base\Element;
use craft\base\Plugin as BasePlugin;
use craft\elements\Asset;
use craft\events\ModelEvent;
use craftyhedge\craftthumbhash\jobs\GenerateThumbhash;
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

        Craft::$app->getView()->registerTwigExtension(new Extension());

        $this->registerEventListeners();
    }

    private function registerEventListeners(): void
    {
        // Generate thumbhash when an image asset is saved
        Event::on(
            Asset::class,
            Element::EVENT_AFTER_SAVE,
            function (ModelEvent $event) {
                /** @var Asset $asset */
                $asset = $event->sender;

                // Skip drafts, revisions, and non-images
                if ($asset->getIsDraft() || $asset->getIsRevision()) {
                    return;
                }

                if ($asset->kind !== Asset::KIND_IMAGE) {
                    return;
                }

                // Skip SVGs
                if (strtolower($asset->getExtension()) === 'svg') {
                    return;
                }

                Craft::$app->getQueue()->push(new GenerateThumbhash([
                    'assetId' => $asset->id,
                ]));
            },
        );

        // Delete thumbhash when an asset is deleted (belt-and-suspenders with FK CASCADE)
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
}

<?php

namespace craftyhedge\craftthumbhash;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\elements\Asset;
use craft\events\DefineMetadataEvent;
use craft\events\ModelEvent;
use craft\events\RegisterElementDefaultTableAttributesEvent;
use craft\events\RegisterElementTableAttributesEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\ReplaceAssetEvent;
use craft\helpers\Html;
use craft\services\Assets;
use craft\services\Utilities;
use craftyhedge\craftthumbhash\jobs\GenerateThumbhash;
use craftyhedge\craftthumbhash\models\Settings;
use craftyhedge\craftthumbhash\records\ThumbhashRecord;
use craftyhedge\craftthumbhash\services\ThumbhashService;
use craftyhedge\craftthumbhash\twig\Extension;
use craftyhedge\craftthumbhash\utilities\ThumbhashUtility;
use yii\base\Event;

/**
 * @property ThumbhashService $thumbhash
 */
class Plugin extends BasePlugin
{
    private const ASSET_TABLE_ATTR_PNG_PREVIEW = 'thumbhashPngPreview';

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
        $registerUtilitiesEvent = defined(Utilities::class . '::EVENT_REGISTER_UTILITIES')
            ? constant(Utilities::class . '::EVENT_REGISTER_UTILITIES')
            : (defined(Utilities::class . '::EVENT_REGISTER_UTILITY_TYPES')
                ? constant(Utilities::class . '::EVENT_REGISTER_UTILITY_TYPES')
                : 'registerUtilityTypes');

        Event::on(
            Utilities::class,
            $registerUtilitiesEvent,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = ThumbhashUtility::class;
            },
        );

        // Show ThumbHash data in the asset details sidebar metadata.
        Event::on(
            Asset::class,
            Element::EVENT_DEFINE_METADATA,
            function(DefineMetadataEvent $event) {
                /** @var Asset $asset */
                $asset = $event->sender;

                if ($asset->kind !== Asset::KIND_IMAGE || strtolower($asset->getExtension()) === 'svg') {
                    return;
                }

                $record = ThumbhashRecord::findOne(['assetId' => $asset->id]);

                $hash = $record?->hash;
                $dataUrl = $record?->dataUrl;
                $displayHash = $hash && strlen($hash) > 42
                    ? substr($hash, 0, 41) . '…'
                    : $hash;

                $event->metadata[Craft::t('thumbhash', 'ThumbHash')] = $hash
                    ? Html::tag('code', Html::encode((string)$displayHash), ['title' => $hash])
                    : Html::tag('span', '-', ['class' => 'light']);

                $event->metadata[Craft::t('thumbhash', '#PNG')] = $dataUrl && str_starts_with($dataUrl, 'data:image/')
                    ? Html::img($dataUrl, [
                        'alt' => '',
                        'width' => 56,
                        'height' => 56,
                        'style' => 'display:block; width:56px; height:56px; object-fit:contain; border-radius:4px;',
                    ])
                    : Html::tag('span', '-', ['class' => 'light']);
            },
        );

        // Add a ThumbHash #PNG column to the Assets index.
        Event::on(
            Asset::class,
            Element::EVENT_REGISTER_TABLE_ATTRIBUTES,
            function(RegisterElementTableAttributesEvent $event) {
                $tableAttributes = $event->tableAttributes;

                if (!$this->shouldShowPngPreviewColumn()) {
                    unset($tableAttributes[self::ASSET_TABLE_ATTR_PNG_PREVIEW]);
                    $event->tableAttributes = $tableAttributes;
                    return;
                }

                $pngPreviewConfig = [
                    'label' => Craft::t('thumbhash', '#PNG'),
                ];

                if (array_key_exists('title', $tableAttributes)) {
                    $ordered = [];
                    foreach ($tableAttributes as $key => $config) {
                        $ordered[$key] = $config;
                        if ($key === 'title') {
                            $ordered[self::ASSET_TABLE_ATTR_PNG_PREVIEW] = $pngPreviewConfig;
                        }
                    }
                    $event->tableAttributes = $ordered;
                    return;
                }

                $event->tableAttributes = [self::ASSET_TABLE_ATTR_PNG_PREVIEW => $pngPreviewConfig] + $tableAttributes;
            },
        );

        // Show the ThumbHash #PNG column by default in the Assets index.
        Event::on(
            Asset::class,
            Element::EVENT_REGISTER_DEFAULT_TABLE_ATTRIBUTES,
            function(RegisterElementDefaultTableAttributesEvent $event) {
                $defaultAttrs = array_values(array_filter(
                    $event->tableAttributes,
                    static fn(string $attr): bool => $attr !== self::ASSET_TABLE_ATTR_PNG_PREVIEW,
                ));

                if (!$this->shouldShowPngPreviewColumn()) {
                    $event->tableAttributes = $defaultAttrs;
                    return;
                }

                $titleIndex = array_search('title', $defaultAttrs, true);
                if ($titleIndex !== false) {
                    array_splice($defaultAttrs, $titleIndex + 1, 0, [self::ASSET_TABLE_ATTR_PNG_PREVIEW]);
                } else {
                    array_unshift($defaultAttrs, self::ASSET_TABLE_ATTR_PNG_PREVIEW);
                }

                $event->tableAttributes = $defaultAttrs;
            },
        );

        $setTableAttributeHtmlEvent = defined(Element::class . '::EVENT_DEFINE_ATTRIBUTE_HTML')
            ? constant(Element::class . '::EVENT_DEFINE_ATTRIBUTE_HTML')
            : (defined(Element::class . '::EVENT_SET_TABLE_ATTRIBUTE_HTML')
                ? constant(Element::class . '::EVENT_SET_TABLE_ATTRIBUTE_HTML')
                : null);

        if ($setTableAttributeHtmlEvent !== null) {
            Event::on(
                Asset::class,
                $setTableAttributeHtmlEvent,
                function($event) {
                    if (!isset($event->attribute) || $event->attribute !== self::ASSET_TABLE_ATTR_PNG_PREVIEW) {
                        return;
                    }

                    if (!$this->shouldShowPngPreviewColumn()) {
                        $event->html = '';
                        return;
                    }

                    /** @var Asset $asset */
                    $asset = $event->sender;

                    if ($asset->kind !== Asset::KIND_IMAGE || strtolower($asset->getExtension()) === 'svg') {
                        $event->html = Html::tag('span', '-', ['class' => 'light']);
                        return;
                    }

                    /** @var ThumbhashRecord|null $record */
                    $record = ThumbhashRecord::findOne(['assetId' => (int)$asset->id]);
                    $dataUrl = $record?->dataUrl;

                    $event->html = $dataUrl && str_starts_with($dataUrl, 'data:image/')
                        ? Html::img($dataUrl, [
                            'alt' => '',
                            'width' => 32,
                            'height' => 32,
                            'style' => 'display:block; width:32px; height:32px; object-fit:contain; border-radius:4px;',
                        ])
                        : Html::tag('span', '-', ['class' => 'light']);
                },
            );
        }

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

    private function shouldShowPngPreviewColumn(): bool
    {
        /** @var Settings $settings */
        $settings = $this->getSettings();

        return (bool)$settings->generateDataUrl;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }
}

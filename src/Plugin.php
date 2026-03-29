<?php

namespace craftyhedge\craftthumbhash;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\elements\Asset;
use craft\events\DefineAttributeHtmlEvent;
use craft\events\DefineMetadataEvent;
use craft\events\ModelEvent;
use craft\events\RegisterElementDefaultTableAttributesEvent;
use craft\events\RegisterElementTableAttributesEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\ReplaceAssetEvent;
use craft\helpers\App;
use craft\helpers\Html;
use craft\log\MonologTarget;
use craft\services\Assets;
use craft\services\Utilities;
use craftyhedge\craftthumbhash\jobs\GenerateThumbhash;
use craftyhedge\craftthumbhash\models\Settings;
use craftyhedge\craftthumbhash\records\ThumbhashRecord;
use craftyhedge\craftthumbhash\services\ThumbhashService;
use craftyhedge\craftthumbhash\twig\Extension;
use craftyhedge\craftthumbhash\utilities\ThumbhashUtility;
use Monolog\Formatter\LineFormatter;
use Psr\Log\LogLevel;
use yii\base\Event;
use yii\base\InvalidConfigException;

/**
 * @property ThumbhashService $thumbhash
 */
class Plugin extends BasePlugin
{
    private const ASSET_TABLE_ATTR_PNG_PREVIEW = 'thumbhashPngPreview';
    private const LOG_TARGET_NAME = 'thumbhash';
    private const LOG_CATEGORY_PREFIX = 'craftyhedge\\craftthumbhash\\*';
    private const LOG_CATEGORY_HANDLE = 'thumbhash';

    /** @var array<int, string|null> */
    private array $assetPreviewDataUrls = [];

    /** @var array<int, bool> */
    private array $assetPreviewLoadedIds = [];

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

        $this->registerLogTarget();

        if (Craft::$app->getRequest()->getIsSiteRequest()) {
            Craft::$app->getView()->registerTwigExtension(new Extension());
        }

        $this->registerEventListeners();
    }

    private function registerLogTarget(): void
    {
        $logDispatcher = Craft::$app->getLog();
        /** @var Settings $settings */
        $settings = $this->getSettings();
        $logDebug = (bool)$settings->logDebug;
        $allowLineBreaks = App::devMode();
        $logLevel = App::devMode()
            ? ($logDebug ? LogLevel::DEBUG : LogLevel::INFO)
            : LogLevel::WARNING;

        foreach ($logDispatcher->targets as $index => $target) {
            if (
                $target instanceof MonologTarget &&
                in_array(self::LOG_CATEGORY_PREFIX, (array)$target->categories, true) &&
                in_array(self::LOG_CATEGORY_HANDLE, (array)$target->categories, true)
            ) {
                unset($logDispatcher->targets[$index]);
                break;
            }
        }

        $formatter = new LineFormatter(
            format: "%datetime% [%channel%.%level_name%] [%extra.yii_category%] %message% %context% %extra%\n",
            dateFormat: 'Y-m-d H:i:s T',
            allowInlineLineBreaks: $allowLineBreaks,
            ignoreEmptyContextAndExtra: true,
        );

        $logDispatcher->targets[] = Craft::createObject([
            'class' => MonologTarget::class,
            'name' => self::LOG_TARGET_NAME,
            'categories' => [
                self::LOG_CATEGORY_PREFIX,
                self::LOG_CATEGORY_HANDLE,
            ],
            'level' => $logLevel,
            'allowLineBreaks' => $allowLineBreaks,
            'logContext' => false,
            'addTimestampToContext' => true,
            'exportInterval' => 1,
            'formatter' => $formatter,
        ]);
    }

    private function registerEventListeners(): void
    {
        Event::on(
            Utilities::class,
            Utilities::EVENT_REGISTER_UTILITIES,
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

        Event::on(
            Asset::class,
            Element::EVENT_DEFINE_ATTRIBUTE_HTML,
            function(DefineAttributeHtmlEvent $event) {
                if ($event->attribute !== self::ASSET_TABLE_ATTR_PNG_PREVIEW) {
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
                $dataUrl = $this->thumbhashDataUrlForAsset($asset);

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

        try {
            $volume = $asset->getVolume();
        } catch (InvalidConfigException $e) {
            Craft::warning(
                sprintf('Skipping ThumbHash generation for asset %s: %s', (string)$asset->id, $e->getMessage()),
                self::LOG_CATEGORY_HANDLE,
            );
            return false;
        }

        return in_array($volume->handle, (array) $volumes, true);
    }

    private function shouldShowPngPreviewColumn(): bool
    {
        /** @var Settings $settings */
        $settings = $this->getSettings();

        return (bool)$settings->generateDataUrl;
    }

    private function thumbhashDataUrlForAsset(Asset $asset): ?string
    {
        $assetId = (int)$asset->id;

        if (!array_key_exists($assetId, $this->assetPreviewLoadedIds)) {
            $this->primeThumbhashDataUrls($asset);
        }

        return $this->assetPreviewDataUrls[$assetId] ?? null;
    }

    private function primeThumbhashDataUrls(Asset $asset): void
    {
        $assetIds = [];
        $queryResult = $asset->elementQueryResult;

        if (is_iterable($queryResult)) {
            foreach ($queryResult as $queriedElement) {
                if ($queriedElement instanceof Asset && $queriedElement->id) {
                    $assetIds[] = (int)$queriedElement->id;
                }
            }
        }

        if (empty($assetIds) && $asset->id) {
            $assetIds[] = (int)$asset->id;
        }

        $assetIds = array_values(array_unique(array_filter($assetIds)));
        $missingIds = array_values(array_filter(
            $assetIds,
            fn(int $assetId): bool => !array_key_exists($assetId, $this->assetPreviewLoadedIds),
        ));

        if (empty($missingIds)) {
            return;
        }

        foreach ($missingIds as $missingId) {
            $this->assetPreviewLoadedIds[$missingId] = true;
            $this->assetPreviewDataUrls[$missingId] = null;
        }

        $records = ThumbhashRecord::find()
            ->select(['assetId', 'dataUrl'])
            ->where(['assetId' => $missingIds])
            ->all();

        foreach ($records as $record) {
            $this->assetPreviewDataUrls[(int)$record->assetId] = $record->dataUrl;
        }
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }
}

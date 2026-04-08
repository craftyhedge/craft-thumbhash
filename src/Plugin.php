<?php

namespace craftyhedge\craftthumbhash;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\elements\Asset;
use craft\events\DefineAttributeHtmlEvent;
use craft\events\DefineMetadataEvent;
use craft\events\ModelEvent;
use craft\events\RegisterElementDefaultTableAttributesEvent;
use craft\events\RegisterElementTableAttributesEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\ReplaceAssetEvent;
use craft\helpers\App;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\log\MonologTarget;
use craft\services\Assets;
use craft\services\Utilities;
use craftyhedge\craftthumbhash\helpers\RuleNormalizer;
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

    /**
     * @return array<string, mixed>
     */
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
                        'width'=> '100px', 
                        'style' => 'display:block; max-width:100px; width:100%; height:auto;  object-fit:contain; border-radius:4px;',
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

        if ($this->shouldAutoGenerateOnAssetEvents()) {
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
    }

    private function pushThumbhashJob(Asset $asset): void
    {
        if (!$this->shouldAutoGenerateOnAssetEvents()) {
            return;
        }

        if ($asset->kind !== Asset::KIND_IMAGE) {
            return;
        }

        if (strtolower($asset->getExtension()) === 'svg') {
            return;
        }

        if (!$this->isAssetAllowed($asset)) {
            return;
        }

        Craft::$app->getQueue()->push(new GenerateThumbhash([
            'assetId' => $asset->id,
        ]));
    }

    public function isAssetAllowed(Asset $asset): bool
    {
        if (!$this->isVolumeAllowed($asset)) {
            return false;
        }

        if (!$this->isAssetIncludedByRules($asset)) {
            return false;
        }

        return !$this->isAssetIgnoredByRules($asset);
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

    public function isAssetIgnoredByRules(Asset $asset): bool
    {
        $rulesByScope = $this->normalizedIgnoreRulesByScope();

        if ($rulesByScope === []) {
            return false;
        }

        $patterns = $this->patternsForAssetScope($asset, $rulesByScope, 'ignore-rule');

        if ($patterns === []) {
            return false;
        }

        if (!$asset->id) {
            return false;
        }

        return Asset::find()
            ->status(null)
            ->site('*')
            ->unique(true)
            ->id((int)$asset->id)
            ->folderPath($patterns)
            ->exists();
    }

    public function isAssetIncludedByRules(Asset $asset): bool
    {
        $rulesByScope = $this->normalizedIncludeRulesByScope();

        if ($rulesByScope === []) {
            return true;
        }

        $patterns = $this->patternsForAssetScope($asset, $rulesByScope, 'include-rule');

        // Include rules are opt-in per scope. If a scope has no include patterns,
        // assets in that scope remain eligible.
        if ($patterns === []) {
            return true;
        }

        if (!$asset->id) {
            return false;
        }

        return Asset::find()
            ->status(null)
            ->site('*')
            ->unique(true)
            ->id((int)$asset->id)
            ->folderPath($patterns)
            ->exists();
    }

    /**
     * @param Query<int|string, mixed> $query
     */
    public function applyFolderRulesToQuery(
        Query $query,
        string $folderPathColumn = 'volumeFolders.path',
        ?string $volumeHandleColumn = 'volumes.handle',
        ?string $volumeIdColumn = null,
    ): void {
        $this->applyIncludeRulesToQuery(
            $query,
            $folderPathColumn,
            $volumeHandleColumn,
            $volumeIdColumn,
        );

        $this->applyIgnoreRulesToQuery(
            $query,
            $folderPathColumn,
            $volumeHandleColumn,
            $volumeIdColumn,
        );
    }

    /**
     * @param Query<int|string, mixed> $query
     */
    public function applyIncludeRulesToQuery(
        Query $query,
        string $folderPathColumn = 'volumeFolders.path',
        ?string $volumeHandleColumn = 'volumes.handle',
        ?string $volumeIdColumn = null,
    ): void {
        $rulesByScope = $this->normalizedIncludeRulesByScope();

        if ($rulesByScope === []) {
            return;
        }

        $globalPatterns = $rulesByScope['*'] ?? [];
        unset($rulesByScope['*']);

        $scopedRules = $rulesByScope;

        $scopedVolumeIdsByHandle = [];
        if ($volumeHandleColumn === null && $volumeIdColumn !== null && $scopedRules !== []) {
            $scopedVolumeIdsByHandle = $this->resolveVolumeIdsByHandle(array_keys($scopedRules));
        }

        $allowConditions = ['or'];

        foreach ($globalPatterns as $pattern) {
            $allowConditions[] = Db::parseParam($folderPathColumn, $pattern);
        }

        foreach ($scopedRules as $scope => $patterns) {
            foreach ($patterns as $pattern) {
                $pathCondition = Db::parseParam($folderPathColumn, $pattern);

                if ($volumeHandleColumn !== null) {
                    $allowConditions[] = [
                        'and',
                        [$volumeHandleColumn => $scope],
                        $pathCondition,
                    ];
                    continue;
                }

                if ($volumeIdColumn !== null && isset($scopedVolumeIdsByHandle[$scope])) {
                    $allowConditions[] = [
                        'and',
                        [$volumeIdColumn => $scopedVolumeIdsByHandle[$scope]],
                        $pathCondition,
                    ];
                }
            }
        }

        // If there are no global include rules, only scoped volumes are constrained.
        // Other volumes remain eligible.
        if ($globalPatterns === []) {
            if ($volumeHandleColumn !== null) {
                $scopedHandles = array_keys($scopedRules);
                if ($scopedHandles !== []) {
                    $allowConditions[] = ['not', [$volumeHandleColumn => $scopedHandles]];
                }
            } elseif ($volumeIdColumn !== null && $scopedVolumeIdsByHandle !== []) {
                $scopedVolumeIds = array_values(array_unique(array_map(
                    static fn(int $id): int => (int)$id,
                    $scopedVolumeIdsByHandle,
                )));

                if ($scopedVolumeIds !== []) {
                    $allowConditions[] = ['not', [$volumeIdColumn => $scopedVolumeIds]];
                }
            }
        }

        if (count($allowConditions) > 1) {
            $query->andWhere($allowConditions);
        }
    }

    /**
     * @param Query<int|string, mixed> $query
     */
    public function applyIgnoreRulesToQuery(
        Query $query,
        string $folderPathColumn = 'volumeFolders.path',
        ?string $volumeHandleColumn = 'volumes.handle',
        ?string $volumeIdColumn = null,
    ): void {
        $rulesByScope = $this->normalizedIgnoreRulesByScope();

        if ($rulesByScope === []) {
            return;
        }

        $volumeIdsByHandle = [];
        if ($volumeHandleColumn === null && $volumeIdColumn !== null) {
            $volumeIdsByHandle = $this->resolveVolumeIdsByHandle(array_keys($rulesByScope));
        }

        $ignoredConditions = ['or'];

        foreach ($rulesByScope as $scope => $patterns) {
            foreach ($patterns as $pattern) {
                $pathCondition = Db::parseParam($folderPathColumn, $pattern);

                if ($scope === '*') {
                    $ignoredConditions[] = $pathCondition;
                    continue;
                }

                if ($volumeHandleColumn !== null) {
                    $ignoredConditions[] = [
                        'and',
                        [$volumeHandleColumn => $scope],
                        $pathCondition,
                    ];
                    continue;
                }

                if ($volumeIdColumn !== null && isset($volumeIdsByHandle[$scope])) {
                    $ignoredConditions[] = [
                        'and',
                        [$volumeIdColumn => $volumeIdsByHandle[$scope]],
                        $pathCondition,
                    ];
                }
            }
        }

        if (count($ignoredConditions) > 1) {
            // Wrap with OR IS NULL so root-folder assets (whose volumefolders.path
            // is NULL) are not accidentally excluded by NOT (NULL LIKE ...) → NULL.
            $query->andWhere([
                'or',
                ['not', $ignoredConditions],
                [$folderPathColumn => null],
            ]);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function normalizedIncludeRulesByScope(): array
    {
        /** @var Settings $settings */
        $settings = $this->getSettings();

        return (new RuleNormalizer())->normalizedRulesByScope($settings->includeRules);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function normalizedIgnoreRulesByScope(): array
    {
        /** @var Settings $settings */
        $settings = $this->getSettings();

        return (new RuleNormalizer())->normalizedRulesByScope($settings->ignoreRules);
    }

    /**
     * @param array<string, array<int, string>> $rulesByScope
     * @return array<int, string>
     */
    private function patternsForAssetScope(Asset $asset, array $rulesByScope, string $ruleType): array
    {
        try {
            $volumeHandle = strtolower($asset->getVolume()->handle);
        } catch (InvalidConfigException $e) {
            Craft::warning(
                sprintf('Skipping ThumbHash %s check for asset %s: %s', $ruleType, (string)$asset->id, $e->getMessage()),
                self::LOG_CATEGORY_HANDLE,
            );

            return [];
        }

        $patterns = $rulesByScope['*'] ?? [];

        if (isset($rulesByScope[$volumeHandle])) {
            $patterns = [...$patterns, ...$rulesByScope[$volumeHandle]];
        }

        return array_values(array_unique($patterns));
    }



    /**
     * @param array<int, string> $scopes
     * @return array<string, int>
     */
    private function resolveVolumeIdsByHandle(array $scopes): array
    {
        $handles = array_values(array_unique(array_filter(
            array_map(
                static fn(string $scope): string => trim(strtolower($scope)),
                $scopes,
            ),
            static fn(string $scope): bool => $scope !== '' && $scope !== '*',
        )));

        if ($handles === []) {
            return [];
        }

        $rows = (new Query())
            ->select(['id', 'handle'])
            ->from([CraftTable::VOLUMES])
            ->where(Db::parseParam('handle', $handles))
            ->andWhere(['dateDeleted' => null])
            ->all();

        $map = [];

        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $handle = $row['handle'] ?? null;

            if (!is_numeric($id) || !is_string($handle) || trim($handle) === '') {
                continue;
            }

            $map[strtolower($handle)] = (int)$id;
        }

        return $map;
    }

    private function shouldAutoGenerateOnAssetEvents(): bool
    {
        /** @var Settings $settings */
        $settings = $this->getSettings();

        return (bool)$settings->autoGenerate;
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

        /** @var ThumbhashRecord $record */
        foreach ($records as $record) {
            $this->assetPreviewDataUrls[(int)$record->assetId] = $record->dataUrl;
        }
    }

    /**
     * @return Settings
     */
    public function getSettings(): ?Model
    {
        /** @var Settings */
        return parent::getSettings();
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }
}

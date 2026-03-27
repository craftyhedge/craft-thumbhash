<?php

namespace craftyhedge\craftthumbhash\utilities;

use Craft;
use craft\base\Utility;
use craft\elements\Asset;
use craftyhedge\craftthumbhash\db\Table;
use craftyhedge\craftthumbhash\Plugin;
use craftyhedge\craftthumbhash\records\ThumbhashRecord;
use yii\db\Expression;

class ThumbhashUtility extends Utility
{
    private const ICON_PATH = __DIR__ . '/../icon-mask.svg';

    private static function isSvgAsset(Asset $asset): bool
    {
        return strtolower((string)$asset->getExtension()) === 'svg';
    }

    public static function displayName(): string
    {
        return Craft::t('thumbhash', 'ThumbHash');
    }

    public static function id(): string
    {
        return 'thumbhash-generator';
    }

    public static function icon(): ?string
    {
        return self::ICON_PATH;
    }

    public static function iconPath(): ?string
    {
        return self::ICON_PATH;
    }

    public static function contentHtml(): string
    {
        $settings = Plugin::getInstance()->getSettings();

        return Craft::$app->getView()->renderTemplate('thumbhash/utilities/index', [
            'initialRows' => self::initialRows(),
            'generateDataUrl' => (bool)$settings->generateDataUrl,
        ]);
    }

    private static function initialRows(): array
    {
        $query = Asset::find()
            ->kind(Asset::KIND_IMAGE)
            ->filename(['not', '*.svg'])
            ->leftJoin(Table::THUMBHASHES . ' thumbhashes', '[[thumbhashes.assetId]] = [[elements.id]]')
            ->orderBy(new Expression("CASE WHEN [[thumbhashes.dataUrl]] IS NULL OR [[thumbhashes.dataUrl]] = '' THEN 0 ELSE 1 END"))
            ->addOrderBy(['elements.id' => SORT_ASC]);

        $settings = Plugin::getInstance()->getSettings();
        $volumes = $settings->volumes;

        if ($volumes !== null && $volumes !== '*') {
            $query->volume((array)$volumes);
        }

        $assets = $query->all();
        $assetIds = array_map(static fn(Asset $asset) => (int)$asset->id, $assets);

        $records = [];
        if (!empty($assetIds)) {
            $records = ThumbhashRecord::find()
                ->where(['assetId' => $assetIds])
                ->indexBy('assetId')
                ->all();
        }

        $rows = [];
        foreach ($assets as $asset) {
            if (self::isSvgAsset($asset)) {
                continue;
            }

            $record = $records[$asset->id] ?? null;
            $dataUrl = $record?->dataUrl;

            $rows[] = [
                'assetId' => (int)$asset->id,
                'name' => (string)($asset->title ?: $asset->filename),
                'editUrl' => (string)($asset->getCpEditUrl() ?? ''),
                'dataUrl' => $dataUrl,
            ];
        }

        return $rows;
    }
}

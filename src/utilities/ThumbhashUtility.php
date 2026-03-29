<?php

namespace craftyhedge\craftthumbhash\utilities;

use Craft;
use craft\base\Utility;
use craftyhedge\craftthumbhash\Plugin;

class ThumbhashUtility extends Utility
{
    private const ICON_PATH = __DIR__ . '/../icon-mask.svg';

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
        $generateDataUrl = (bool)$settings->generateDataUrl;

        $initialRows = [];
        $initialHashRows = [];

        if ($generateDataUrl) {
            $initialRows = self::initialRows();

            if (empty($initialRows)) {
                $initialHashRows = self::initialHashRows();
            }
        } else {
            $initialHashRows = self::initialHashRows();
        }

        return Craft::$app->getView()->renderTemplate('thumbhash/utilities/index', [
            'initialRows' => $initialRows,
            'initialHashRows' => $initialHashRows,
            'generateDataUrl' => $generateDataUrl,
        ]);
    }

    private static function initialRows(): array
    {
        return Plugin::getInstance()->thumbhash->getUtilityPngRows();
    }

    private static function initialHashRows(): array
    {
        return Plugin::getInstance()->thumbhash->getUtilityHashRows();
    }
}

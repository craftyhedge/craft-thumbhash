<?php

namespace craftyhedge\craftthumbhash\utilities;

use Craft;
use craft\base\Utility;

class ThumbhashUtility extends Utility
{
    public static function displayName(): string
    {
        return Craft::t('thumbhash', 'ThumbHash Generator');
    }

    public static function id(): string
    {
        return 'thumbhash-generator';
    }

    public static function icon(): ?string
    {
        return 'image';
    }

    public static function iconPath(): ?string
    {
        return null;
    }

    public static function contentHtml(): string
    {
        return Craft::$app->getView()->renderTemplate('thumbhash/utilities/index');
    }
}

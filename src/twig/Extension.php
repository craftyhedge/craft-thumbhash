<?php

namespace craftyhedge\craftthumbhash\twig;

use Craft;
use craft\elements\Asset;
use craft\web\View;
use craftyhedge\craftthumbhash\Plugin;
use craftyhedge\craftthumbhash\web\assets\ThumbhashBundle;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use yii\helpers\Json;

class Extension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('thumbhash', [$this, 'getThumbhash']),
            new TwigFunction('thumbhashDataUrl', [$this, 'getThumbhashDataUrl']),
            new TwigFunction('thumbhashScript', [$this, 'getThumbhashScript'], [
                'is_safe' => ['html'],
            ]),
        ];
    }

    /**
     * Returns the base64 thumbhash string for an asset, or null.
     */
    public function getThumbhash(?Asset $asset): ?string
    {
        if (!$asset) {
            return null;
        }

        return Plugin::getInstance()->thumbhash->getHash($asset->id);
    }

    /**
     * Returns the thumbhash decoded as a PNG data URL for an asset, or null.
     */
    public function getThumbhashDataUrl(?Asset $asset): ?string
    {
        if (!$asset) {
            return null;
        }

        return Plugin::getInstance()->thumbhash->getDataUrl($asset->id);
    }

    /**
     * Registers the client-side ThumbHash decoder asset bundle.
     */
    public function getThumbhashScript(): string
    {
        $settings = Plugin::getInstance()->getSettings();

        Craft::$app->getView()->registerJs(
            'window.thumbhashConfig = window.thumbhashConfig || {}; window.thumbhashConfig.backgroundPlaceholderStyles = ' . Json::htmlEncode((array)$settings->backgroundPlaceholderStyles) . ';',
            View::POS_HEAD,
        );

        Craft::$app->getView()->registerAssetBundle(ThumbhashBundle::class);

        return '';
    }
}

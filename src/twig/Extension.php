<?php

namespace craftyhedge\craftthumbhash\twig;

use Craft;
use craft\elements\Asset;
use craft\web\View;
use craftyhedge\craftthumbhash\Plugin;
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
     * Registers the client-side ThumbHash decoder as inline JS.
     */
    public function getThumbhashScript(): string
    {
        $settings = Plugin::getInstance()->getSettings();
        $view = Craft::$app->getView();

        $view->registerJs(
            'window.thumbhashConfig = window.thumbhashConfig || {}; window.thumbhashConfig.backgroundPlaceholderStyles = ' . Json::htmlEncode((array)$settings->backgroundPlaceholderStyles) . ';',
            View::POS_HEAD,
        );

        $scriptPath = dirname(__DIR__) . '/web/assets/dist/th-decoder.min.js';
        $scriptContents = file_get_contents($scriptPath);

        $view->registerJs($scriptContents, View::POS_HEAD);

        return '';
    }
}

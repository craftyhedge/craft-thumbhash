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
            new TwigFunction('thumbhashTransparentSvg', [$this, 'getTransparentSvgDataUrl']),
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
     * Returns a transparent SVG placeholder data URL.
     */
    public function getTransparentSvgDataUrl(int $width = 4, int $height = 4): string
    {
        $width = max(1, $width);
        $height = max(1, $height);

        $svg = sprintf(
            "<svg xmlns='http://www.w3.org/2000/svg' width='%d' height='%d' style='background:transparent'/>",
            $width,
            $height,
        );

        return 'data:image/svg+xml;charset=utf-8,' . rawurlencode($svg);
    }

    /**
     * Registers the client-side ThumbHash decoder as inline JS.
     */
    public function getThumbhashScript(): string
    {
        $settings = Plugin::getInstance()->getSettings();
        $view = Craft::$app->getView();

        $position = $settings->scriptPosition === 'end' ? View::POS_END : View::POS_HEAD;

        $view->registerJs(
            'window.thumbhashConfig = window.thumbhashConfig || {};'
            . ' window.thumbhashConfig.renderMethod = ' . Json::htmlEncode($settings->renderMethod) . ';'
            . ' window.thumbhashConfig.backgroundPlaceholderStyles = ' . Json::htmlEncode((array)$settings->backgroundPlaceholderStyles) . ';',
            $position,
        );

        $scriptPath = dirname(__DIR__) . '/web/assets/dist/th-decoder.min.js';
        $scriptContents = file_get_contents($scriptPath);

        $view->registerJs($scriptContents, $position);

        return '';
    }
}

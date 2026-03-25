<?php

namespace craftyhedge\craftthumbhash\twig;

use craft\elements\Asset;
use craftyhedge\craftthumbhash\Plugin;
use craftyhedge\craftthumbhash\web\assets\ThumbhashBundle;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Craft;

class Extension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('thumbhash', [$this, 'getThumbhash']),
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
     * Outputs the <script> tag for the client-side ThumbHash decoder.
     * Uses Craft's asset bundle system to register the JS.
     */
    public function getThumbhashScript(): string
    {
        $bundle = Craft::$app->getView()->registerAssetBundle(ThumbhashBundle::class);
        $baseUrl = $bundle->baseUrl;
        $filename = 'thumbhash-decode.js';

        return '<script src="' . htmlspecialchars($baseUrl . '/' . $filename, ENT_QUOTES, 'UTF-8') . '" defer></script>';
    }
}

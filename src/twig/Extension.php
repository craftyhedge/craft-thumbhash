<?php

namespace craftyhedge\craftthumbhash\twig;

use Craft;
use craft\elements\Asset;
use craftyhedge\craftthumbhash\Plugin;
use craftyhedge\craftthumbhash\web\assets\ThumbhashBundle;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

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
     * Registers the client-side ThumbHash decoder asset bundle.
     */
    public function getThumbhashScript(): string
    {
        Craft::$app->getView()->registerAssetBundle(ThumbhashBundle::class);

        return '';
    }
}

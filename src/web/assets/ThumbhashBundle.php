<?php

namespace craftyhedge\craftthumbhash\web\assets;

use craft\web\AssetBundle;
use craftyhedge\craftthumbhash\Plugin;

class ThumbhashBundle extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->js = ['thumbhash-decode.js'];
        $this->jsOptions = ['defer' => (bool)Plugin::getInstance()->getSettings()->deferDecoderScript];

        parent::init();
    }
}

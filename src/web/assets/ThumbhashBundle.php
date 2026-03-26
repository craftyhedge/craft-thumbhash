<?php

namespace craftyhedge\craftthumbhash\web\assets;

use craft\web\AssetBundle;

class ThumbhashBundle extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->js = ['thumbhash-decode.js'];
        $this->jsOptions = ['defer' => true];

        parent::init();
    }
}

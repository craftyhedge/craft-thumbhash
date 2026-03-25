<?php

namespace craftyhedge\craftthumbhash\web\assets;

use craft\web\AssetBundle;

class ThumbhashBundle extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';

        parent::init();
    }
}

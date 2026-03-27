<?php

namespace craftyhedge\craftthumbhash\models;

use craft\base\Model;

class Settings extends Model
{
    /**
     * Volume handles to generate thumbhashes for.
     * Set to `null` or `'*'` for all volumes (default).
     * Set to an array of volume handles to restrict generation.
     *
     * @var string[]|string|null
     */
    public array|string|null $volumes = '*';

    /**
     * Whether to generate and store decoded PNG data URLs.
     * Disable this to skip PNG creation and only store hash strings.
     */
    public bool $generateDataUrl = true;
}

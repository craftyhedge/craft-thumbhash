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

    /**
     * Whether hash generation should use a Craft image transform as input.
     * Useful when transforms are offloaded to an external image service.
     */
    public bool $useTransformSource = false;

    /**
     * Transform definition used when `useTransformSource` is enabled.
     *
     * @var array<string, mixed>
     */
    public array $sourceTransform = [
        'mode' => 'fit',
        'width' => 100,
        'height' => 100,
    ];

    /**
     * Maximum retry attempts when transform source is not ready.
     */
    public int $transformSourceMaxAttempts = 4;

    /**
     * Delay in seconds between transform source retries.
     */
    public int $transformSourceRetryDelay = 15;
}

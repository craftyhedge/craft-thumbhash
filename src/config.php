<?php

/**
 * ThumbHash plugin config
 *
 * Copy this file to config/thumbhash.php in your Craft project.
 */

return [
    // Volume handles to generate thumbhashes for.
    // Set to '*' for all volumes (default), or an array of volume handles.
    // 'volumes' => '*',
    // 'volumes' => ['images', 'hero'],

    // Generate and store the decoded PNG data URL (~300 bytes per asset).
    // Set to false to disable PNG creation and only store hash strings.
    // Default: true
    // 'generateDataUrl' => true,

    // Use a Craft transform URL as the source image for hash generation.
    // Helpful when transforms are offloaded to an external image service.
    // Default: false
    // 'useTransformSource' => false,

    // Transform definition used when useTransformSource is enabled.
    // Default: fit 100x100
    // 'sourceTransform' => [
    //     'mode' => 'fit',
    //     'width' => 100,
    //     'height' => 100,
    // ],

    // Retry behavior when the transform source is not ready yet.
    // Default: 4 attempts with 15s delay
    // 'transformSourceMaxAttempts' => 4,
    // 'transformSourceRetryDelay' => 15,
];

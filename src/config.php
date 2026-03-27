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
];

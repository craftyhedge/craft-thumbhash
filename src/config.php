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

    // Generate and store the decoded PNG data URL (typically ~0.8-2KB per asset).
    // Set to false to disable PNG creation and only store hash strings.
    // Default: true
    // 'generateDataUrl' => true,

    // Use PNG compression for generated data URLs.
    // If false, falls back to the standard uncompressed ThumbHash PNG encoder.
    // Default: true
    // 'pngCompressionEnabled' => true,

    // PNG compression level for generated data URLs (0-9).
    // Higher values reduce size but are slower to encode.
    // Default: 9
    // 'pngCompressionLevel' => 9,

    // Strip metadata from Imagick-generated PNGs.
    // Ignored when Imagick is unavailable.
    // Default: true
    // 'pngStripMetadata' => true,

    // Transform definition used as the source image for hash generation.
    // Default: fit 100x100
    // 'sourceTransform' => [
    //     'mode' => 'fit',
    //     'width' => 100,
    //     'height' => 100,
    // ],

    // CSS styles applied automatically when using `data-thumbhash-bg`.
    // Set to an empty array to disable the auto-applied background styles.
    // Default: no-repeat / cover / center
    // 'backgroundPlaceholderStyles' => [
    //     'backgroundRepeat' => 'no-repeat',
    //     'backgroundSize' => 'cover',
    //     'backgroundPosition' => 'center',
    // ],

    // Load the frontend ThumbHash decoder script with the defer attribute.
    // Set to false to keep the script non-deferred.
    // Default: false
    // 'deferDecoderScript' => false,

    // Include debug-level plugin logs when dev mode is enabled.
    // Default: false
    // 'logDebug' => false,
];

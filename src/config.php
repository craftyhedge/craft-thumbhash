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

    // Include folder path rules keyed by scope.
    // Use '*' for global rules, or a volume handle for volume-specific rules.
    // Rule values support '*' wildcards and are matched against asset folder paths.
    // Volumes without include rules stay eligible unless '*' is configured.
    // Values without '*' are treated as folder prefixes (for example: 'products' => 'products/*').
    // 'includeRules' => [
    //     'images' => ['products/*', 'hero/*'],
    // ],

    // Ignore folder path rules keyed by scope.
    // Use '*' for global rules, or a volume handle for volume-specific rules.
    // Rule values support '*' wildcards and are matched against asset folder paths.
    // Ignore rules are applied after include rules.
    // Values without '*' are treated as folder prefixes (for example: 'private' => 'private/*').
    // 'ignoreRules' => [
    //     '*' => ['cache/*'],
    //     'images' => ['private', 'archive/2023/*'],
    // ],

    // Automatically queue thumbhash generation on asset save/replace events.
    // Set to false to disable event-driven generation and run manually via Utility/CLI.
    // Default: true
    // 'autoGenerate' => true,

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
    // Default: fit width 100 (height auto)
    // 'sourceTransform' => [
    //     'mode' => 'fit',
    //     'width' => 100,
    // ],

    // CSS styles applied automatically when using `data-thumbhash-bg`.
    // Set to an empty array to disable the auto-applied background styles.
    // Default: no-repeat / cover / center
    // 'backgroundPlaceholderStyles' => [
    //     'backgroundRepeat' => 'no-repeat',
    //     'backgroundSize' => 'cover',
    //     'backgroundPosition' => 'center',
    // ],

    // Include debug-level plugin logs when dev mode is enabled.
    // Default: false
    // 'logDebug' => false,

    // Maximum allowed transform response size in bytes.
    // Helps avoid excessive memory use when transform endpoints misbehave.
    // Default: 5242880 (5 MiB)
    // 'transformFetchMaxBytes' => 5242880,

    // Total timeout in seconds for transform fetch requests.
    // Default: 20.0
    // 'transformFetchTimeout' => 20.0,

    // Connection timeout in seconds for transform fetch requests.
    // Default: 5.0
    // 'transformFetchConnectTimeout' => 5.0,

    // Per-read timeout in seconds for streamed transform responses.
    // Default: 10.0
    // 'transformFetchReadTimeout' => 10.0,

    // Maximum concurrent HTTP connections during batch prefetch.
    // Users on CDN-backed transforms (e.g. Imgix) may benefit from a higher
    // value (8–10) to speed up batch prefetch.
    // Default: 3
    // 'fetchConcurrency' => 3,
];

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
     * Folder include rules keyed by scope.
     * Use `*` for global rules or a volume handle for per-volume rules.
     * Rule values are folder path patterns that support `*` wildcards.
     *
     * @var array<string, string|array<int, string>>
     */
    public array $includeRules = [];

    /**
     * Folder ignore rules keyed by scope.
     * Use `*` for global rules or a volume handle for per-volume rules.
     * Rule values are folder path patterns that support `*` wildcards.
     *
     * @var array<string, string|array<int, string>>
     */
    public array $ignoreRules = [];

    /**
     * Whether to automatically queue generation on asset save/replace events.
     */
    public bool $autoGenerate = true;

    /**
     * Whether to generate and store decoded PNG data URLs.
     * Disable this to skip PNG creation and only store hash strings.
     */
    public bool $generateDataUrl = true;

    /**
     * Whether ThumbHash PNG data URLs should use compressed encoding.
     * If disabled, falls back to the library's standard uncompressed encoder.
     */
    public bool $pngCompressionEnabled = true;

    /**
     * PNG compression level for encoded data URLs (0-9).
     * Higher values are smaller but slower to encode.
     */
    public int $pngCompressionLevel = 9;

    /**
     * Whether metadata should be stripped from Imagick-encoded PNG output.
     */
    public bool $pngStripMetadata = true;

    /**
     * Transform definition used as the source image for hash generation.
     *
     * @var array<string, mixed>
     */
    public array $sourceTransform = [
        'mode' => 'fit',
        'width' => 100,
    ];

    /**
     * CSS styles applied automatically when `data-thumbhash-bg` is used.
     *
     * @var array<string, string>
     */
    public array $backgroundPlaceholderStyles = [
        'backgroundRepeat' => 'no-repeat',
        'backgroundSize' => 'cover',
        'backgroundPosition' => 'center',
    ];

    /**
     * Whether the frontend decoder script should be loaded with the defer attribute.
     */
    public bool $deferDecoderScript = false;

    /**
     * Whether to include debug-level plugin logs in dev mode.
     */
    public bool $logDebug = false;

    /**
     * Maximum allowed transform response size in bytes.
     */
    public int $transformFetchMaxBytes = 5242880;

    /**
     * Total timeout (seconds) for transform fetch requests.
     */
    public float $transformFetchTimeout = 20.0;

    /**
     * Connection timeout (seconds) for transform fetch requests.
     */
    public float $transformFetchConnectTimeout = 5.0;

    /**
     * Read timeout (seconds) for streamed transform responses.
     */
    public float $transformFetchReadTimeout = 10.0;
}

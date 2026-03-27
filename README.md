# ThumbHash for Craft CMS

Automatic [ThumbHash](https://evanw.github.io/thumbhash/) placeholder generation for Craft CMS image assets.

## What is ThumbHash?

ThumbHash is a compact image placeholder.

- For the lightest HTML payload, use the base64 hash string with the included client-side JS decoder. Recommended.
- For zero-JavaScript placeholders, use the pre-decoded PNG data URL. This typically adds around ~1KB per image to the HTML, depending on dimensions and content.
- PNG data URLs are often highly compressible with gzip or Brotli.
- The JS decoder is typically very fast for small hashes, and because the script is deferred it does not block initial HTML parsing. Decoding still runs on the main thread, so total cost scales with the number of placeholders.


<table>
    <tr>
        <td><img width="200px" height="268px" src="assets/thumbhash.png" alt="Example of a thumbhash placeholder decoded to a tiny PNG data URL" /></td>
        <td><img width="200px" height="268px" src="assets/example.avif" alt="Example full image" /></td>
    </tr>
</table>

## Requirements

- Craft CMS 5.0+
- PHP 8.2+
- Imagick extension (recommended) or GD for fallback (note: GD's alpha channel support is limited to 1-bit transparency, so results may be less smooth)
- External transform service recommended for best performance (e.g. Imgix, Cloudflare Images)

## Installation

```bash
composer require craftyhedge/craft-thumbhash
php craft plugin/install thumbhash
```

## Usage

### Twig Templates

The decoder script will decode each hash to a tiny PNG data URL and set it as the `src` on the element. Your lazy loading library (lazysizes, lozad, etc.) handles swapping `data-src` → `src` when the element enters the viewport.

```twig
{# Register the decoder asset (safe to call; Craft includes it once per page) #}
{{ thumbhashScript() }}

{# For each image, use data-thumbhash with your preferred lazy loading approach #}
{% set hash = thumbhash(asset) %}

<img data-thumbhash="{{ hash }}" data-src="{{ asset.url }}" alt="{{ asset.title }}" width="{{ asset.width }}" height="{{ asset.height }}" />
```

If you want to use the hash with a CSS background instead of inlining `thumbhashDataUrl()`, pass the hash directly to `data-thumbhash-bg`. The decoder will populate `style.backgroundImage` and apply the configured background placeholder styles for you. By default that is `background-repeat: no-repeat`, `background-size: cover`, and `background-position: center`:

```twig
{{ thumbhashScript() }}

{% set hash = thumbhash(asset) %}

<div class="relative z-0 w-full h-auto" data-thumbhash-bg="{{ hash }}">
    <img
        class="relative z-10 block w-full h-auto lazyload"
        width="{{ asset.width }}"
        height="{{ asset.height }}"
        data-src="{{ asset.getUrl() }}"
    />
</div>
```

```css
img.lazyload,
img.lazyloading {
    @apply opacity-0;
}

img.lazyloaded {
    @apply opacity-100;
    animation: lazy-image-fade-in 700ms ease-out both;
}

@keyframes lazy-image-fade-in {
    from {
        opacity: 0;
    }

    to {
        opacity: 1;
    }
}

img.lazyload:not([src]) {
    visibility: hidden;
}
```


### How It Works

1. **On new image upload or file replacement**: A queue job generates the ThumbHash from a resized copy (≤100×100px) of the image
2. **In templates**: `thumbhash(asset)` returns the base64 hash string (~28 bytes)
3. **In the browser**: The decoder JS finds elements using either `data-thumbhash` or `data-thumbhash-bg`, decodes each hash to a tiny PNG data URL, and sets it as `src` or `background-image` when `data-thumbhash-bg` is present

### Inline Mode (No JavaScript)

If you prefer to skip the JS decoder entirely, use `thumbhashDataUrl()` to inline the placeholder directly:

```twig
{% set placeholder = thumbhashDataUrl(asset) %}

<img src="{{ placeholder }}" data-src="{{ asset.url }}" alt="{{ asset.title }}" width="{{ asset.width }}" height="{{ asset.height }}" />
```

This adds around ~0.8-2KB per image to your HTML (vs ~40 bytes for the hash attribute), but placeholders are visible on first paint with zero JavaScript. The data URL is pre-computed and stored in the database alongside the hash. Stored values are PNG-compressed when available, with a fallback to the standard ThumbHash encoder.

### Template Functions

| Function | Description |
|---|---|
| `thumbhash(asset)` | Returns the base64 thumbhash string for an asset, or `null` |
| `thumbhashDataUrl(asset)` | Returns the thumbhash decoded as a PNG data URL, or `null` |
| `thumbhashScript()` | Registers the client-side decoder asset bundle |

### JavaScript API

The decoder exposes a global API for manual use:

```js
// Decode a base64 thumbhash to a data URL
var dataUrl = window.thumbhash.toDataURL('BASE64_HASH');

// Or get a CSS background-image value
var backgroundImage = window.thumbhash.toBackgroundImage('BASE64_HASH');
```

To override the auto-applied background styles, set `backgroundPlaceholderStyles` in `config/thumbhash.php`:

```php
<?php

return [
    'backgroundPlaceholderStyles' => [
        'backgroundRepeat' => 'no-repeat',
        'backgroundSize' => 'cover',
        'backgroundPosition' => 'top center',
    ],
];
```

Set it to an empty array if you want `data-thumbhash-bg` to set only `background-image` and leave all other background styles alone.

## Configuration

Create `config/thumbhash.php` in your Craft project to limit generation by volume handle:

```php
<?php

return [
    // Default: all volumes
    'volumes' => '*',

    // Or restrict generation to specific volumes
    // 'volumes' => ['images', 'hero'],

    // Generate and store the base64 thumbhash string (~28 bytes per asset).
    // Used with the client-side JS decoder. Default: true
    // 'generateHash' => true,

    // Generate and store the decoded PNG data URL (typically ~0.8-2KB per asset).
    // Used for inline placeholders without JavaScript. Set to false to disable PNG creation.
    // Default: true
    // 'generateDataUrl' => true,

    // Use PNG compression for generated data URLs.
    // If false, uses the standard uncompressed ThumbHash PNG encoder.
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

    // Use a Craft image transform as the source for hash generation.
    // Helpful when transforms are offloaded to an external image service.
    // Default: true
    // 'useTransformSource' => true,

    // Transform definition used when useTransformSource is enabled.
    // Default: fit 100x100
    // 'sourceTransform' => [
    //     'mode' => 'fit',
    //     'width' => 100,
    //     'height' => 100,
    // ],

    // Retry behavior when transform source is not ready yet.
    // After max attempts, generation falls back to direct source processing.
    // Defaults: 4 attempts with 15s delay
    // 'transformSourceMaxAttempts' => 4,
    // 'transformSourceRetryDelay' => 15,

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
];
```

### Transform Source

For the best server performance, it is recommended to use an external transform service like Imgix or Cloudflare Images.

To ensure ThumbHash works with those transform services, a plugin is needed to replace the native Craft transform service.

#### Imgixer

[Imgixer](https://github.com/croxton/imgixer/) is a Craft plugin that provides an Imgix transform source. If you're using Imgixer, you can configure it as the transform source for ThumbHash.

If you are using Imgixer for Craft transforms, configure an Imgix source in `config/imgixer.php` and point Imgixer's transform source to it:

```php
<?php

use craft\helpers\App;

return [
    'sources' => [
        'imgix' => [
            'provider' => 'imgix',
            'endpoint' => App::env('IMGIX_DOMAIN'),
            'privateKey' => App::env('IMGIX_KEY'),
            'signed' => true,
            'defaultParams' => ['auto' => 'compress,format'],
        ],
        'assetTransforms' => [
            'provider' => 'imgix',
            'endpoint' => App::env('IMGIX_DOMAIN'),
            'privateKey' => App::env('IMGIX_KEY'),
            'signed' => true,
            'defaultParams' => ['auto' => 'compress,format'],
        ],
    ],
    'transformSource' => 'assetTransforms',
];
```

You can disable transform-source mode for ThumbHash in `config/thumbhash.php` to fall back to the original source file, but this will lead to increased server load and slower generation times:

```php
<?php

return [
    'useTransformSource' => false,
    'sourceTransform' => [
        'mode' => 'fit',
        'width' => 100,
        'height' => 100,
    ],
];
```

## Backfilling Existing Assets

To generate thumbhashes for assets that existed before the plugin was installed:

```bash
# All image assets
php craft thumbhash/generate

# Specific volume only
php craft thumbhash/generate --volume=images
```

This command queues a batch job and returns immediately with the queued job ID. Processing starts when your Craft queue runner picks up the job.

## Clearing Stored Thumbhashes

From the Control Panel Utility:

- Open Utilities → ThumbHash
- Click `Clear All`
- Confirm the prompt to delete all stored thumbhash records

From CLI:

```bash
php craft thumbhash/generate/clear --yes=1

# Clear only stored PNG data URLs, keep thumbhash strings
php craft thumbhash/generate/clear-data-urls --yes=1
```

The `--yes=1` flag is required as a safety guard for this destructive action.

## How the Decoder Works

The included JS decoder:

- Finds all `[data-thumbhash]` elements on the page
- Decodes each base64 hash to a tiny PNG data URL and sets it as `src` (LQIP placeholder)
- Watches for dynamically added elements via `MutationObserver`
- Does **not** include lazy loading — bring your own (lazysizes, lozad, native `loading="lazy"`, etc.)

## Notes

- **SVGs are skipped** — they can't be rasterized to pixels for hashing
- **Animated GIFs** — only the first frame is hashed
- **Imagick is preferred** over GD for proper 8-bit alpha channel support
- Hashes are stored in a custom `thumbhashes` DB table with a foreign key cascade to the elements table

## Logging

This plugin registers its own log target and writes to:

- `storage/logs/thumbhash-YYYY-MM-DD.log`

Notes:

- In dev mode, info/warning/error messages are logged by default.
- Set `logDebug` to `true` in `config/thumbhash.php` to include debug-level plugin events in dev mode.
- In non-dev mode, warning/error messages are logged.

## License

The Craft License — see [LICENSE.md](LICENSE.md).

The client-side decoder includes code from [evanw/thumbhash](https://github.com/evanw/thumbhash) (MIT License).

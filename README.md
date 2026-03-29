# ThumbHash for Craft CMS

Automatic thumbhash placeholder generation for Craft CMS image assets.

## What is ThumbHash?

ThumbHash is a tiny visual fingerprint of an image. It stores the image's overall color and structure in a very small base64 string (typically around 28 bytes), which can be decoded into a lightweight PNG placeholder.

That gives users an immediate, content-aware preview while the full image loads, improving perceived performance and reducing layout jank.

This plugin supports two frontend delivery approaches:

### JS Decoder

- Inline a tiny base64 hash string (~28 bytes) in your HTML and use the included client-side JS decoder to convert it to a small PNG data URL on the fly.

### Inline Data URLs

- For zero-JavaScript placeholders, inline the pre-decoded PNG data URL. No network requests or JS decoding, while still being smaller and usually better looking than most regular LQIPs.

### Backend
- Triggers a fit transform (default width 100px, height auto) of the original image and encodes it to a compact base64 hash string (~28 bytes) using the ThumbHash algorithm.
- Decodes the hash to a PNG data URL and stores it in the database for inline use without JavaScript.
- ThumbHash generation is performed asynchronously in a queue job to avoid blocking the request thread.
- When `autoGenerate` is enabled (default), placeholders are generated on new uploads and file replacements, with a CLI command for backfilling existing assets.

### JS Decoder vs. Inline Data URLs

#### JS Decoder (hash in markup)

Use this when you want the smallest per-image HTML payload.

- Store only the base64 hash in markup (typically around ~28 bytes).
- Decode happens in the browser and is typically very fast (often well under 1ms on desktop-class runtimes; mobile varies by device).
- No extra network request for the placeholder.
- Uses the standard browser-side ThumbHash PNG encoder, so it does not apply the plugin's server-side PNG compression settings.

#### Inline Data URLs (pre-decoded PNG)

Use this when you want a no-JavaScript placeholder path.

- Store the PNG data URL directly in markup (typically around ~0.8-2KB per image before HTTP compression).
- No decode step in the browser and no extra network request.
- When `generateDataUrl` is enabled (default), the plugin can store a server-generated, compressed PNG data URL that is often smaller than client-decoding the same hash.

#### Quick rule of thumb

- Prefer JS Decoder for smallest HTML payload per image.
- Prefer Inline Data URLs for zero-JS rendering.
- Both approaches avoid an extra placeholder request.

If your images are hosted externally and that service goes down for whatever reason, users will always get a placeholder.

## Example

This example shows how good a ThumbHash placeholder can look. Getting this kind of smooth gradient from heavily compressed, blurred LQIP-style placeholders usually means a larger payload and often an extra request.

<table>
    <tr>
        <td><img width="300px" height="402px" src="assets/thumbhash-example.png" alt="Example of a thumbhash placeholder decoded to a tiny PNG data URL" /></td>
        <td><img width="300px" height="402px" src="assets/image-example.avif" alt="Example full image" /></td>
    </tr>
</table>

Photo by <a href="https://unsplash.com/@sanjeevan_s?utm_source=unsplash&utm_medium=referral&utm_content=creditCopyText">Sanjeevan  SatheesKumar</a> on <a href="https://unsplash.com/photos/tree-surrounded-by-grass-MG8c-4n1QVE?utm_source=unsplash&utm_medium=referral&utm_content=creditCopyText">Unsplash</a>


## Requirements

- Craft CMS 5.0+
- PHP 8.2+

## Recommendations
- External transform service recommended for best performance (e.g. Imgix, Cloudflare Images)

## Installation

```bash
composer require craftyhedge/craft-thumbhash
php craft plugin/install thumbhash
```

## Usage

### Twig Templates

The decoder script will decode each hash to a tiny PNG data URL and set it as the `src` on the element.
For the earliest possible placeholder paint, keep the decoder script registered in the `<head>` and leave the default setting `deferDecoderScript` set to `false` so the decoder can run as soon as possible.

This is a deliberate tradeoff, not a blanket performance rule. A non-deferred script in the `<head>` can add some parser-blocking work, but it also allows placeholders to appear sooner. If you would rather minimize the impact of a head script and can accept placeholders appearing a little later, set `deferDecoderScript` to `true`.

```twig
{# Register the decoder asset (safe to call; Craft includes it once per page) #}
{{ thumbhashScript() }}
```

Your lazy loading library (lazysizes, lozad, etc.) handles swapping `data-src` → `src` when the element enters the viewport.

```twig
{# For each image, use data-thumbhash with your preferred lazy loading approach #}
{% set hash = thumbhash(asset) %}

<img data-thumbhash="{{ hash }}" data-src="{{ asset.url }}" alt="{{ asset.title }}" width="{{ asset.width }}" height="{{ asset.height }}" />
```

### Smooth Load Transitions

Lazysizes example:

To achieve a nice smooth fade use the hash with a CSS background. 

Pass the hash directly to `data-thumbhash-bg`. The decoder will populate `style.backgroundImage` and apply the configured background placeholder styles for you. By default that is `background-repeat: no-repeat`, `background-size: cover`, and `background-position: center`.

```twig
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

For the no JS decoding option, you can use `thumbhashDataUrl()` to get the decoded PNG data URL directly and set it as an inline background image:

```twig
{% set placeholder = thumbhashDataUrl(asset) %}

<div class="relative z-0 w-full h-auto" style="background-image: url('{{ placeholder }}'); background-repeat: no-repeat; background-size: cover; background-position: center;">
    <img
        class="relative z-10 block w-full h-auto lazyload"
        width="{{ asset.width }}"
        height="{{ asset.height }}"
        data-src="{{ asset.getUrl() }}"
    />
</div>
```

### CSS for Lazyloading Class Swaps

Lazysizes example:

```css
img.lazyload,
img.lazyloading {
    @apply opacity-0;
}

img.lazyloaded {
    @apply opacity-100;
    animation: lazy-image-fade-in 500ms ease-out both;
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

### Template Functions

| Function | Description |
|---|---|
| `thumbhash(asset)` | Returns the base64 thumbhash string for an asset, or `null` |
| `thumbhashDataUrl(asset)` | Returns the thumbhash decoded as a PNG data URL, or `null` |
| `thumbhashScript()` | Registers the client-side decoder asset bundle |

### Control Panel

For supported image assets, the plugin also surfaces ThumbHash data in the Craft control panel:

- Asset details show a `ThumbHash` metadata field with the stored hash string.
- Asset details show a `#PNG` metadata preview when a PNG data URL is available.
- The Assets index gets a `#PNG` preview column by default when `generateDataUrl` is enabled.

### Control Panel Utility

The plugin also adds a `Utilities -> ThumbHash` screen for maintenance tasks:

- Queue generation for missing or modified image assets.
- Preview stored PNG placeholders across assets.
- Clear all stored thumbhash records.

<img width="620px" height="340px" src="assets/thumbhash-panel.png" alt="ThumbHash control panel utility showing the PNG preview grid and generation tools" />

### JavaScript API

The decoder exposes a global API for manual use:

This API mirrors the default ThumbHash browser encoder. It does not use the plugin's server-side compressed PNG generation path.

- Scope: `window.thumbhash` is a browser global and only exists on pages where `thumbhashScript()` is included.
- Availability: it is available to any frontend JavaScript (vanilla JS, Alpine, React, Vue, etc.) after the decoder script has loaded.
- Not available in PHP or CLI contexts.

Example:

```js
// Decode a base64 thumbhash to a data URL
var dataUrl = window.thumbhash.toDataURL('BASE64_HASH');

// Or get a CSS background-image value
var backgroundImage = window.thumbhash.toBackgroundImage('BASE64_HASH');
```


## Configuration

Create `config/thumbhash.php` in your Craft project to control generation behavior:

```php
<?php

return [
    // Default: all volumes
    'volumes' => '*',

    // Or restrict generation to specific volumes
    // 'volumes' => ['images', 'hero'],

    // Automatically queue thumbhash generation on asset save/replace events.
    // Set to false to disable event-driven generation and run manually via Utility/CLI.
    // Default: true
    // 'autoGenerate' => true,

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

    // Load the frontend ThumbHash decoder script with the defer attribute.
    // Set to false to keep the script non-deferred.
    // Default: false
    // 'deferDecoderScript' => false,

    // Include debug-level plugin logs when dev mode is enabled.
    // Default: false
    // 'logDebug' => false,
];
```

## Transform Source

For the best server performance, it is recommended to use an external transform service like Imgix or Cloudflare Images.

If your project is set up to replace native Craft transforms with an external service, ThumbHash should use it too. You can verify the source URL used for hash generation in the ThumbHash logs.

## Performance Considerations

- ThumbHash generation is performed asynchronously in a queue job to avoid blocking the request thread.
- If `autoGenerate` is enabled, uploading or replacing an image asset will trigger a new hash generation job for that asset.
- When uploading large numbers of assets at once Craft limits uploads to small batches so you only ever get a few hash generation jobs queued at once.
- The actual generation work to hash the transformed image is typically quite fast but the real bottleneck is the image transform step, especially if you are using the default server-side transforms. Using an external transform service can significantly reduce generation time and improve overall performance.
- Reprocessing unchanged assets will reuse existing transforms, so subsequent runs are typically much faster after the initial batch.

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

## Notes

- **SVGs are skipped** — they can't be rasterized to pixels for hashing
- **Animated GIFs** — only the first frame is hashed
- **Imagick is preferred** over GD for proper 8-bit alpha channel support
- Hashes and png urls are stored in a custom `thumbhashes` DB table with a foreign key cascade to the elements table

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

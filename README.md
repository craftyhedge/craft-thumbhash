# ThumbHash for Craft CMS

[![CI](https://github.com/craftyhedge/craft-thumbhash/actions/workflows/ci.yml/badge.svg)](https://github.com/craftyhedge/craft-thumbhash/actions/workflows/ci.yml)
[![PHPStan Level 7](https://img.shields.io/badge/PHPStan-level%207-brightgreen?logo=php)](https://github.com/craftyhedge/craft-thumbhash/blob/master/phpstan.neon)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%20|%208.3%20|%208.4-777BB4?logo=php&logoColor=white)](https://github.com/craftyhedge/craft-thumbhash)
[![Craft 5](https://img.shields.io/badge/Craft%20CMS-5.0%2B-e5422b?logo=craft-cms&logoColor=white)](https://github.com/craftyhedge/craft-thumbhash)

Automatic thumbhash placeholder generation for Craft CMS image assets.

## Table of Contents

- [What is ThumbHash?](#what-is-thumbhash)
- [Example](#example)
- [Requirements](#requirements)
- [Installation](#installation)
- [Basic Usage](#basic-usage)
- [Rendering](#rendering)
- [Configuration](#configuration)
- [Performance & Scaling](#performance--scaling)
- [Control Panel Features](#control-panel-features)
- [CLI & Maintenance](#cli--maintenance)
- [Reference](#reference)
- [Notes](#notes)
- [Logging](#logging)
- [License](#license)

## What is ThumbHash?

ThumbHash is a tiny visual fingerprint of an image — a ~28-byte base64 string that captures the overall color and structure. This plugin generates those hashes on the backend and ships a small client-side JS decoder that turns each hash into a PNG placeholder right in the browser.

The result: an immediate, content-aware preview while the full image loads, improving perceived performance and reducing layout jank — with virtually zero per-image cost in your HTML.

### How it works

1. **Backend** — On upload (or via CLI), the plugin creates a small transform of the original image and encodes it to a ~28-byte base64 hash using the ThumbHash algorithm. Generation runs in a queue job so it never blocks a request.
2. **Frontend** — A single inline decoder script (~5 KB minified) registers a MutationObserver that automatically converts every `data-thumbhash` attribute into a PNG placeholder data URL as the DOM is built. It then applies that placeholder using the configured render method (`bg` by default, or `img`/`picture`). No extra network requests, no visible pop-in.

That's it for most sites. Drop the hash in your markup, register the script once, and placeholders appear before images even start loading.

Using the `bg` mode even provides LQIPs for eager loaded images! Since the placeholder is decoupled from lazy loading the full image simply overlays the placeholder once downloaded natively by the browser.


### Optional: Inline PNG Data URLs

If you need placeholders without any JavaScript — for example in RSS feeds, AMP pages, or HTML emails — the plugin can also pre-decode each hash to a PNG data URL on the server and store it in the database.

- Typically ~0.8–2 KB per image (before HTTP compression), still smaller and better-looking than most blurred LQIPs.
- The backend generator automatically reads the original image dimensions and applies an exact center-crop to the decoded PNG to perfectly match the asset's aspect ratio, preventing layout shifts.
- Opt in with the `generateDataUrl` setting (enabled by default).
- Use `thumbhashDataUrl(asset)` in your templates to get the ready-made data URL.

For regular websites, the JS decoder is the recommended approach — it keeps per-image markup tiny and works great with lazy-loading libraries and infinite scroll.

## Example

ThumbHash placeholders retain accurate colors and smooth gradients. They encode information to a string that is smaller than a typical LQIP URL!


Check it out - [ThumbHash Example](https://craftyhedge.github.io/thumbhash-example/)

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

## Installation

```bash
composer require craftyhedge/craft-thumbhash
php craft plugin/install thumbhash
```

## Basic Usage

> **Note:** The inline script `{{ thumbhashScript() }}` must be included on any page where you want placeholders to render unless using the No-JS option. 

The decoder script decodes each hash to a tiny PNG data URL and applies it according to the render method (`bg`, `img`, or `picture`; default is `bg`).
The script is inlined (no extra HTTP request). By default it is registered in `<head>` (configurable via `scriptPosition`), which allows the MutationObserver to start before `<body>` elements are parsed so placeholders appear as the DOM is built rather than after.

```twig
{# Register the inline decoder script (safe to call; Craft includes it once per page) #}
{{ thumbhashScript() }}
```

Your lazy loading library (lazysizes, lozad, etc.) handles swapping `data-src` → `src` when the element enters the viewport.

For `<img>` placeholders written directly to `src`, either set `data-thumbhash-render="img"` per element, or set `renderMethod` to `'img'` globally in config.

```twig
{# For each image, use data-thumbhash with your preferred lazy loading approach #}
{% set hash = thumbhash(asset) %}

<img data-thumbhash="{{ hash }}" data-thumbhash-render="img" data-src="{{ asset.url }}" alt="{{ asset.title }}" width="{{ asset.width }}" height="{{ asset.height }}" />
```

## Rendering

### Background Image Support

Pass the hash with `data-thumbhash` and set `data-thumbhash-render="bg"` (or rely on the global default). The decoder will populate `style.backgroundImage` and apply the configured background placeholder styles for you. By default that is `background-repeat: no-repeat`, `background-size: cover`, and `background-position: center`.

A distinct benefit of the background image approach is that it **decouples your LQIP from lazyloading**. Eager-loaded images display the placeholder instantly, and the full image seamlessly overlays it once downloaded natively by the browser.

```twig
{% set hash = thumbhash(asset) %}

<div class="relative z-0 w-full h-auto" data-thumbhash="{{ hash }}" data-thumbhash-render="bg">
    <img src="{{ thumbhashTransparentSvg() }}" alt="{{ asset.title }}"
        class="relative z-10 block w-full h-auto lazyload"
        width="{{ asset.width }}"
        height="{{ asset.height }}"
        data-src="{{ asset.getUrl() }}"
    />
</div>
```

Use `src="{{ thumbhashTransparentSvg() }}"` when using background mode. This prevents empty `src` violations and native browser 'no image' placeholders.

### Picture Element Support

The decoder also supports `<picture>` elements with multiple `<source>` children. When `data-thumbhash` is placed on a `<picture>` with `data-thumbhash-render="picture"`, the decoder propagates the hash to child `<source data-srcset>` and `<img>` elements so each gets its own placeholder.

The placeholder ratio is derived from the `width` and `height` attributes on each element, so you can have different aspect ratios for each breakpoint if needed. If no valid dimensions are found, the decoder falls back to the ThumbHash's native decoded dimensions (no ratio crop/resize).

```twig
<picture data-thumbhash="3OcRJYB4d3h/iIeHeEh3eIhw+j2w" data-thumbhash-render="picture">
    <source data-srcset="hero-lg.webp" media="(min-width: 1024px)" width="1200" height="800">
    <source data-srcset="hero-md.webp" media="(max-width: 1023px)" width="800" height="600">

    <img data-srcset="hero-sm.webp" alt="Mountain view" width="600" height="400" class="lazyload">
</picture>
```

### What should you use?

| Method | Use Case |
|---|---|
| `bg` | Most use cases (recommended default) |
| `picture` | Responsive images with multiple sources/aspect ratios |
| `img` | Single images without responsive breakpoints |

> **Note:** Due to the nature of ThumbHash placeholders, the accuracy the picture method provides tends to be overkill. ThumbHashes are blurry and slight differences between it and the final image are very hard to notice for most use cases. Background placeholders with `cover` sizing do the job very well and have the bonus of giving eager loaded images LQIPs too!

### Fallback vs. Explicit Rendering

Choose one of these patterns depending on how much per-element control you need.

**Approach A: Explicit per element (recommended for mixed projects)**

Set `data-thumbhash-render` on each element (or wrapper) so your CSS can branch safely by mode.

```twig
{% set hash = thumbhash(asset) %}

{# bg mode on a wrapper #}
<div class="img-wrapper" data-thumbhash="{{ hash }}" data-thumbhash-render="bg">
    <img
        src="{{ thumbhashTransparentSvg() }}"
        data-src="{{ asset.url }}"
        alt="{{ asset.title }}"
        width="{{ asset.width }}"
        height="{{ asset.height }}"
        class="lazyload"
    />
</div>

{# img mode on the image itself #}
<img
    class="img-wrapper lazyload"
    data-thumbhash="{{ hash }}"
    data-thumbhash-render="img"
    data-src="{{ asset.url }}"
    alt="{{ asset.title }}"
    width="{{ asset.width }}"
    height="{{ asset.height }}"
/>
```

**Approach B: Config fallback (minimal markup)**

Omit `data-thumbhash-render` and let the plugin-wide `renderMethod` config decide mode.

```twig
{% set hash = thumbhash(asset) %}

<div class="img-wrapper" data-thumbhash="{{ hash }}">
    <img
        src="{{ thumbhashTransparentSvg() }}"
        data-src="{{ asset.url }}"
        alt="{{ asset.title }}"
        width="{{ asset.width }}"
        height="{{ asset.height }}"
        class="lazyload"
    />
</div>
```

In this fallback approach, CSS cannot infer the active mode from element attributes alone. Use mode-agnostic CSS or add a global page class that mirrors your configured default mode.

```php
return [
    // ...
    'renderMethod' => 'bg', // or 'img' or 'picture'
];
``` 

### No JavaScript Option

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

### CSS for Smooth Lazyloading Class Swaps

`.img-wrapper` is just an example wrapper class for your image elements, adjust as needed for your markup. This could be a `picture` element.

If you are mixing modes, prefer explicit `data-thumbhash-render` attributes and use mode-aware CSS:

```css
/* Only hide the real image while loading when placeholder is on container background */
.img-wrapper[data-thumbhash][data-thumbhash-render="bg"] img.lazyload,
.img-wrapper[data-thumbhash][data-thumbhash-render="bg"] img.lazyloading {
    opacity: 0;
}

.img-wrapper[data-thumbhash][data-thumbhash-render="bg"] img.lazyloaded {
    animation: lazy-image-fade-in 130ms cubic-bezier(0.2, 0, 0, 1) both;
}

@keyframes lazy-image-fade-in {
    from { opacity: 0; }
    to { opacity: 1; }
}
```

## Configuration

Copy the plugin config template from [src/config.php](src/config.php) to `config/thumbhash.php` in your Craft project, then uncomment and adjust only the options you need:

- [src/config.php](src/config.php)

Minimal example:

```php
<?php

return [
    'volumes' => '*',
    // 'autoGenerate' => true,
    // 'renderMethod' => 'bg',
    // 'scriptPosition' => 'head',
    // 'generateDataUrl' => true,
    // 'fetchConcurrency' => 3,
];
```

### Folder Rules

You can scope thumbhash generation to specific folders within volumes using `includeRules` and `ignoreRules`. Both are keyed by scope: use `'*'` for global rules or a volume handle for volume-specific rules. Rule values support `*` wildcards and are matched against asset folder paths. Values without `*` are treated as folder prefixes (e.g. `'products'` becomes `'products/*'`).

Ignore rules are applied after include rules. Volumes without include rules stay eligible unless a global `'*'` include rule is configured.

```php
return [
    'volumes' => '*',

    // Only generate for assets in these folders
    'includeRules' => [
        'images' => ['products/*', 'hero/*'],
    ],

    // Skip assets in these folders (applied after include rules)
    'ignoreRules' => [
        '*' => ['cache/*'],
        'images' => ['private', 'archive/2023/*'],
    ],
];
```

## Performance & Scaling

A [decoder benchmark tool](https://craftyhedge.github.io/craft-thumbhash/benchmark.html) is available for measuring client-side decode performance in your browser.

### Transform Source

For the best server performance, it is recommended to use an external transform service like Imgix or Cloudflare Images.

If your project is set up to replace native Craft transforms with an external service, ThumbHash should use it too. You can verify the source URL used for hash generation in the ThumbHash logs.

For example, Imgix users could use the Imgixer plugin and configure it to replace the Craft transform source:

```php
return [
    'sources' => [
        'imgix' => [
            'provider' => 'imgix',
            'endpoint' => App::env('IMGIX_DOMAIN'),
            'privateKey'   => App::env('IMGIX_KEY'),
            'signed'    => true,
            'defaultParams' => ['auto' => 'compress,format']
        ],
    ],
    'transformSource' => 'imgix', // <-- the important part :)
];
```
Now ThumbHash and all your CP images will use the Imgix source for transforms.

- **Servd Hosting:** Works great on [Servd](https://servd.host/) hosting. Use their plugin to replace Craft transforms with Servd's image optimization service.

### Transform Concurrency

When generating thumbhashes for large batches of assets, the plugin needs to fetch many transformed images. The `fetchConcurrency` setting controls how many HTTP requests it will make in parallel during this prefetch step.

The default concurrency is 3, which is conservative and safe for local transforms. If you use a CDN-backed transform service that handles concurrent requests well, you can increase `fetchConcurrency` (e.g. 8–10) to speed up batch prefetch significantly.

The difference with 10+ concurrent fetches on 100s or 1000s of assets can be dramatic.

If you push this too high you might see some failed fetches due to rate limits or server resource constraints, so adjust according to your hosting environment and transform source capabilities.

With all this praise of external transform services, it's worth noting that the default Craft transform generation still works just fine with this plugin. It just won't be as fast for large batches of assets like when you first backfill existing assets.

If you are developing and need to clear the stored thumbhashes for whatever reason, the transforms will be reused on the next generation run, so subsequent runs will be much faster after the initial generation.

### Asynchronous Generation

- ThumbHash generation is performed asynchronously in a queue job to avoid blocking the request thread.
- If `autoGenerate` is enabled, uploading or replacing an image asset will trigger a new hash generation job for that asset.
- When uploading large numbers of assets, Craft processes them in small batches only triggering a few hash generation jobs at any one time.

## Control Panel Features

### Asset Metadata
For supported image assets, the plugin also surfaces ThumbHash data in the Craft control panel:

- Asset details show a `ThumbHash` metadata field with the stored hash string.
- Asset details show a `#PNG` metadata preview when a PNG data URL is available.
- The Assets index gets a `#PNG` preview column by default when `generateDataUrl` is enabled.

### Maintenance Utility

The plugin also adds a `Utilities -> ThumbHash` utility panel for maintenance tasks:

- Queue generation for missing or modified image assets.
- Preview stored PNG placeholders across assets.
- Clear all stored thumbhash records.


## CLI & Maintenance

### Backfilling Existing Assets

To generate thumbhashes for assets that existed before the plugin was installed:

```bash
# All image assets (uses configured volumes setting)
php craft thumbhash/generate

# Specific volume (overrides the configured volumes setting)
php craft thumbhash/generate --volume=images
```

Folder rules (`includeRules`/`ignoreRules`) always apply. `--volume` overrides the configured `volumes` allowlist but does not bypass folder rules.

This command queues a batch job and returns immediately with the queued job ID. Processing starts when your Craft queue runner picks up the job.

Large asset sets with server-side transforms can be slow — consider running during low-traffic periods or using an external transform service.

### Clearing Stored Thumbhashes

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

## Reference

### Template Functions

| Function | Description |
|---|---|
| `thumbhash(asset)` | Returns the base64 thumbhash string for an asset, or `null` |
| `thumbhashDataUrl(asset)` | Returns the thumbhash decoded as a PNG data URL, or `null` |
| `thumbhashTransparentSvg(width = 4, height = 4)` | Returns a transparent SVG placeholder data URL |
| `thumbhashScript()` | Registers the client-side decoder (position controlled by `scriptPosition` setting) |

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

// Optional second arg: target aspect ratio (width / height)
// Example: crop/resize placeholder to 16:9
var dataUrl16x9 = window.thumbhash.toDataURL('BASE64_HASH', 16 / 9);
```

If no valid target ratio is available, `toDataURL()` returns the placeholder at the ThumbHash's native decoded dimensions.

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

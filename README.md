# ThumbHash for Craft CMS

Automatic [ThumbHash](https://evanw.github.io/thumbhash/) placeholder generation for Craft CMS image assets.

## What is ThumbHash?

ThumbHash is a compact representation of an image placeholder (~28 bytes). The hash is stored inline in your HTML and decoded instantly by JavaScript in the browser — no extra HTTP requests needed.

## Requirements

- Craft CMS 4.4+ or 5.0+
- PHP 8.2+
- Imagick extension (recommended) or GD

## Installation

```bash
composer require craftyhedge/craft-thumbhash
php craft plugin/install thumbhash
```

## Usage

### In Twig Templates

The decoder script will decode each hash to a tiny PNG data URL and set it as the `src` on the element. Your lazy loading library (lazysizes, lozad, etc.) handles swapping `data-src` → `src` when the element enters the viewport.

```twig
{# Register the decoder asset (safe to call; Craft includes it once per page) #}
{{ thumbhashScript() }}

{# For each image, use data-thumbhash with your preferred lazy loading approach #}
{% set hash = thumbhash(asset) %}

<img data-thumbhash="{{ hash }}" data-src="{{ asset.url }}" alt="{{ asset.title }}" width="{{ asset.width }}" height="{{ asset.height }}" />
```

For a smooth transition from the placeholder to the full image, you have to get a bit more inventive. Lazysizes JS library example:

```twig
{% set hash = thumbhash(asset) %}

<div class="relative z-0 w-full h-auto overflow-clip">
    <img
        class="relative z-10 block w-full h-auto lazyload"
        alt="{{ asset.title }}"
        width="{{ asset.width }}"
        height="{{ asset.height }}"
        data-src="{{ asset.getUrl() }}"
    />

    <img
        class="absolute inset-0 w-full h-full pointer-events-none -z-1"
        data-thumbhash="{{ hash }}"
        width="{{ asset.width }}"
        height="{{ asset.height }}"
        alt=""
        aria-hidden="true"
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

In this example, there are a few key things happening:
- Because there is no src attribute on either of these img tags, there is css to hide them until their sources are swapped in. Prevents showing any 'no image' browser placeholders.
- The thumbhash image is absolutely positioned behind the main image, so it will be visible until the main image loads and fades in on top of it.
- The main image has a fade-in animation to make the transition from the placeholder to the full image smoother. This is key to dealing with the class swapping from the lazy loading library.




### How It Works

1. **On new image upload or file replacement**: A queue job generates the ThumbHash from a resized copy (≤100×100px) of the image
2. **In templates**: `thumbhash(asset)` returns the base64 hash string (~28 bytes)
3. **In the browser**: The decoder JS finds all `[data-thumbhash]` elements, decodes each hash to a tiny PNG data URL, and sets it as `src`

### Inline Mode (No JavaScript)

If you prefer to skip the JS decoder entirely, use `thumbhashDataUrl()` to inline the placeholder directly:

```twig
{% set placeholder = thumbhashDataUrl(asset) %}

<img src="{{ placeholder }}" data-src="{{ asset.url }}" alt="{{ asset.title }}" width="{{ asset.width }}" height="{{ asset.height }}" />
```

This adds ~300 bytes per image to your HTML (vs ~40 bytes for the hash attribute), but placeholders are visible on first paint with zero JavaScript. The data URL is pre-computed and stored in the database alongside the hash — no runtime decode cost.

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
```

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

    // Generate and store the decoded PNG data URL (~300 bytes per asset).
    // Used for inline placeholders without JavaScript. Set to false to disable PNG creation.
    // Default: true
    // 'generateDataUrl' => true,
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

## License

The Craft License — see [LICENSE.md](LICENSE.md).

The client-side decoder includes code from [evanw/thumbhash](https://github.com/evanw/thumbhash) (MIT License).

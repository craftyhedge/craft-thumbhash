# ThumbHash for Craft CMS

Automatic [ThumbHash](https://evanw.github.io/thumbhash/) placeholder generation for Craft CMS image assets.

## What is ThumbHash?

ThumbHash is a compact representation of an image placeholder (~28 bytes). The hash is stored inline in your HTML and decoded instantly by JavaScript in the browser — no extra HTTP requests needed.

## Requirements

- Craft CMS 4.0+ or 5.0+
- PHP 8.2+
- Imagick extension (recommended) or GD

## Installation

```bash
composer require craftyhedge/craft-thumbhash
php craft plugin/install thumbhash
```

## Usage

### In Twig Templates

```twig
{# Output the decoder script (once per page, ideally in <head>) #}
{{ thumbhashScript() }}

{# For each image, use data-thumbhash with your preferred lazy loading approach #}
{% set hash = thumbhash(asset) %}
{% if hash %}
  <img data-thumbhash="{{ hash }}" data-src="{{ asset.url }}" alt="{{ asset.title }}" width="{{ asset.width }}" height="{{ asset.height }}" />
{% else %}
  <img src="{{ asset.url }}" alt="{{ asset.title }}" width="{{ asset.width }}" height="{{ asset.height }}" />
{% endif %}
```

The decoder script will decode each hash to a tiny PNG data URL and set it as the `src` on the element. Your lazy loading library (lazysizes, lozad, etc.) handles swapping `data-src` → `src` when the element enters the viewport.

### How It Works

1. **On asset save**: A queue job generates the ThumbHash from a resized copy (≤100×100px) of the image
2. **In templates**: `thumbhash(asset)` returns the base64 hash string (~28 bytes)
3. **In the browser**: The decoder JS finds all `[data-thumbhash]` elements, decodes each hash to a tiny PNG data URL, and sets it as `src`

### Template Functions

| Function | Description |
|---|---|
| `thumbhash(asset)` | Returns the base64 thumbhash string for an asset, or `null` |
| `thumbhashScript()` | Outputs the `<script>` tag for the client-side decoder |

### JavaScript API

The decoder exposes a global API for manual use:

```js
// Decode a base64 thumbhash to a data URL
var dataUrl = window.thumbhash.toDataURL('BASE64_HASH');
```

## Backfilling Existing Assets

To generate thumbhashes for assets that existed before the plugin was installed:

```bash
# All image assets
php craft thumbhash/generate

# Specific volume only
php craft thumbhash/generate --volume=images
```

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

MIT — see [LICENSE.md](LICENSE.md).

The client-side decoder includes code from [evanw/thumbhash](https://github.com/evanw/thumbhash) (MIT License).

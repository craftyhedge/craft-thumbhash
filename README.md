# ThumbHash for Craft CMS

Automatic [ThumbHash](https://evanw.github.io/thumbhash/) placeholder generation for Craft CMS 5 image assets.

## What is ThumbHash?

ThumbHash is a compact representation of an image placeholder (~28 bytes). The hash is stored inline in your HTML and decoded instantly by JavaScript in the browser — no extra HTTP requests needed.

## Requirements

- Craft CMS 5.3.0+
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

{# For each image, use data-thumbhash and data-src #}
{% set hash = thumbhash(asset) %}
{% if hash %}
  <img data-thumbhash="{{ hash }}" data-src="{{ asset.url }}" alt="{{ asset.title }}" width="{{ asset.width }}" height="{{ asset.height }}" />
{% else %}
  <img src="{{ asset.url }}" alt="{{ asset.title }}" width="{{ asset.width }}" height="{{ asset.height }}" />
{% endif %}
```

### How It Works

1. **On asset save**: A queue job generates the ThumbHash from a resized copy (≤100×100px) of the image
2. **In templates**: `thumbhash(asset)` returns the base64 hash string (~28 bytes)
3. **In the browser**: The decoder JS finds all `[data-thumbhash]` elements, decodes each hash to a tiny PNG data URL placeholder, then lazy-loads the real image from `data-src`

### Template Functions

| Function | Description |
|---|---|
| `thumbhash(asset)` | Returns the base64 thumbhash string for an asset, or `null` |
| `thumbhashScript()` | Outputs the `<script>` tag for the client-side decoder |

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
- Decodes each base64 hash to a tiny PNG data URL and sets it as `src`
- Uses `IntersectionObserver` to lazy-load the real image from `data-src`
- Watches for dynamically added elements via `MutationObserver`

## Notes

- **SVGs are skipped** — they can't be rasterized to pixels for hashing
- **Animated GIFs** — only the first frame is hashed
- **Imagick is preferred** over GD for proper 8-bit alpha channel support
- Hashes are stored in a custom `thumbhashes` DB table with a foreign key cascade to the elements table

## License

MIT — see [LICENSE.md](LICENSE.md).

The client-side decoder includes code from [evanw/thumbhash](https://github.com/evanw/thumbhash) (MIT License).

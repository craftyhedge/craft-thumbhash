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
        <td><img width="200px" height="268px" src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABcAAAAgCAYAAAD5VeO1AAAMRklEQVR4AQBdAKL/AE0eO/9OHzz/USE8/1QjPf9XJT3/Wic9/10oPP9fKTv/YSo7/2MqO/9lKz3/Zy1A/2ovRP9rMEn/bDFN/2svUf9nLFL/YSZR/1keTv9QFUr/SA1F/0EHQf89Az//AF0Aov8AUCA9/1EhPf9UIz7/VyY//1ooP/9eKj//YCw+/2ItPv9kLT3/Zi4+/2kvQP9rMUP/bjNH/3A1TP9wNVH/bzNU/2swVf9lKlT/XSJR/1QZTP9MEUj/RQtE/0EHQf8AXQCi/wBVJUD/ViZA/1koQf9cK0L/YC5D/2QwQ/9nMkP/aTNC/2w0Qv9uNUP/cTdF/3M5Sf92O03/eD1S/3k9V/93O1r/czhb/20xWv9lKVb/XCFS/1MYTf9MEkn/SQ5G/wBdAKL/AFsrQ/9dLET/YC9F/2MxRv9nNUf/azdI/286SP9yPEj/dT1J/3g/Sv97QU3/fkNQ/4FGVf+DR1r/hEhf/4JGYv9+QmP/dzxh/280Xv9mK1n/XSJU/1ccUP9TGE3/AF0Aov8AYjFH/2QzR/9nNUn/azlK/3A8TP90QE3/eENO/3xFTv+AR1D/g0pS/4dMVf+KT1n/jVJe/5BUY/+QVGf/j1Jq/4tOa/+ESGr/e0Bm/3I3Yf9pLlz/YihY/18kVf8AXQCi/wBpOEn/azlK/248S/9yQE3/d0RP/3xIUf+BS1L/hk5U/4pSVv+OVVj/klhc/5ZbYf+aXmb/nGBr/51hcP+cX3P/mFt0/5FVcv+ITG7/f0Np/3Y7ZP9vNGD/azBd/wBdAKL/AG49Sv9wP0v/dEJN/3lGT/9+SlH/hE9T/4lTVf+OV1j/k1pa/5heXf+dYmL/omZn/6Vpbf+obHL/qW13/6hrev+kZ3v/nWF5/5VZdf+LT3D/gkdr/3xAZ/94PWX/AF0Aov8Ac0FK/3VDS/94Rkz/fUpP/4NPUf+JVFT/j1lX/5VdWf+bYl3/oGZg/6VqZf+rb2v/r3Nx/7J2d/+zd3z/snV//65ygP+oa3//n2N7/5Zadv+NUnH/h0xt/4NIav8AXQCi/wB1REj/d0VJ/3tJS/+ATU3/hlJQ/41XU/+TXFb/mWFZ/59mXP+la2H/q3Bm/7F1bP+2enP/uX15/7t+fv+6fYL/tnqD/7B0gv+obH7/n2R6/5Zbdf+QVXH/jFJv/wBdAKL/AHZFRf94R0b/fEpH/4FOSv+HU03/jllQ/5ReU/+bY1b/oWla/6huX/+uc2T/tHlr/7l9cf+9gXj/v4N+/76Cgf+7f4P/tXqC/61yf/+lanv/nWJ2/5Zccv+TWXD/AF0Aov8AdkVB/3hHQv97SkP/gU5G/4ZTSP+NWEv/k15O/5pjUf+gaVX/p25a/610YP+zeWb/uX5t/72CdP+/hHr/v4R+/7yBgP+3fH//r3V9/6duef+gZ3X/mmFy/5decP8AXQCi/wB0RDz/dkY9/3lJPv9+TUD/hFJD/4pXRf+QXEj/lmFL/51mT/+jbFT/qnFZ/7B3YP+1fGf/uYBt/7yCc/+8gnj/uYB6/7R8ev+udXj/pm51/59ocf+aYm7/l19s/wBdAKL/AHFDN/9yRDj/dkc5/3pKO/9/Tz3/hVM//4tYQf+RXUT/l2JH/51nS/+jbFD/qXJX/653Xf+ye2T/tX1q/7V9b/+zfHH/r3hy/6lycP+ibG7/m2Vr/5ZhaP+TXmb/AF0Aov8AbEAy/21BM/9wRDP/dEc1/3lLNv9+Tzf/g1M5/4lXO/+OXD7/lGBB/5llRv+fakz/pG9S/6hyWf+qdV//q3Zj/6l0Zv+lcWf/oGxm/5lmZP+TYGL/j1xf/4xZXv8AXQCi/wBmPC3/Zz0t/2k/Lv9tQi7/cUUv/3VJMP96TDH/f1Ay/4RUNP+IWDf/jVw7/5JgQP+XZUb/m2hM/51rUf+da1b/nGpZ/5hnWv+TY1r/jl1Z/4hYV/+EVFX/glJU/wBdAKL/AF43J/9fOCf/YTon/2Q8KP9oPyj/a0Io/29FKP9zSCn/d0sq/3xOLP+AUi//hFY0/4hZOf+LXD7/jV5E/45fSP+MXkv/iVtN/4VXTf+AU0z/e05L/3dLSf91SUj/AF0Aov8AVDIi/1UyIf9XNCH/WjYh/104If9gOiD/Yz0g/2c/IP9qQiD/bkQi/3FHJP91Sij/eE0s/3tQMf98Ujb/fVI6/3tRPf94Tz//dExA/3BHP/9sRD7/aEA9/2Y/Pf8AXQCi/wBKKxz/Sywc/0wtHP9PLxv/UjEb/1QzGv9XNRn/WjcY/105GP9gOxn/Yz0a/2U/Hf9oQiH/akQl/2tFKv9rRi7/akUx/2hDM/9kQDT/YDw0/1w5M/9ZNjP/WDUy/wBdAKL/AD8lGP9AJhj/QicY/0QpF/9GKhb/SSwV/0suE/9OLxL/UDER/1IyEv9VNBP/VzYV/1k4GP9aORz/Wzog/1s7I/9aOib/WDgp/1U2Kv9RMyv/TjAr/0wuK/9KLCr/AF0Aov8ANSAW/zYhFf83IhX/OSMU/zwlE/8+JxL/QSgQ/0MqD/9FKw7/RywN/0ktDv9KLg//TDAS/00xFf9NMhn/TTIc/0wxIP9KMCL/SC4k/0UsJf9CKSb/QCcm/z8mJv8AXQCi/wAsHBX/LB0V/y4eFP8wIBT/MyET/zUjEf84JRD/OiYO/zwnDf89KAz/PykM/0AqDf9BKw//QiwS/0IsFf9CLBn/QSwc/z8rH/89KSL/Oygj/zkmJP83JSX/NiQl/wBdAKL/ACQaF/8lGxf/JxwW/ykeFv8sIBX/LiIU/zEjEv8zJRH/NSYP/zYnDv83Jw7/OCgP/zkoEP86KRP/OikW/zkqGf85KR3/Nykg/zYoI/80JyX/MyYn/zElKP8xJCj/AF0Aov8AHhob/x8aG/8hHBv/Ix4a/yYgGf8pIhj/LCQX/y4lFv8wJxT/MScT/zIoE/8zKBP/MygU/zQpFv80KRn/Mykd/zMqIP8yKST/MSkn/zApKv8vKC3/Lygu/y4oL/8AXQCi/wAaGyH/Gxwh/x0dIf8gHyD/IyIg/yYkH/8pJh7/Kygc/y0pG/8uKhr/LyoZ/y8qGf8vKhr/Lyoc/y8rH/8vKyL/Lysm/y8sKv8vLC7/Liwy/y4tNP8tLTb/LS03/wBdAKL/ABYdJ/8YHif/GiAo/x0iJ/8gJCf/Iycm/yYpJf8pKyT/Kiwi/yssIf8sLSD/LCwf/ywsIP8sLCL/LC0l/ywtKP8sLiz/LC8x/y0wNv8tMTr/LTI9/y0yQP8tMkH/AF0Aov8AFB8v/xUgL/8XIi//GiUv/x4nLv8hKi7/JCwt/ycuK/8oLyn/KS8o/ykvJv8pLib/KS4m/yguJ/8oLir/KC8u/ykwMv8qMTf/KjM9/ys1Qf8sNkX/LTdI/y03Sv8AXQCi/wASIjX/EyM1/xUkNf8YJzX/HCo1/x8sNP8iLjP/JDAx/yUxL/8mMS3/JjAs/yUwK/8lLyv/JC8s/yQvLv8kMDL/JTE3/yYzPP8oNUL/KTdH/yo5TP8rOk//LDtR/wBdAKL/AA8jO/8RJDv/EyY7/xYoO/8ZKzr/HC45/x8wOP8hMTb/IjE0/yIxMf8iMC//IS8u/yAuLv8fLi7/Hy4x/x8vNf8gMDr/IjM//yQ1Rf8mOEv/JzpQ/yk8VP8qPVb/AF0Aov8ADSQ//w4lP/8QJz//Eyk//xYsPv8aLj3/HDA7/x4xOf8fMTf/HjA0/x4vMf8cLjD/Gywv/xosL/8ZLDL/Gi01/xsuO/8dMUH/HzRH/yE3Tf8kOlP/JTxX/yY9Wf8AXQCi/wALJEL/DCVC/w4nQv8RKUH/FCxB/xcuP/8ZLz7/GjA7/xswOP8aLzX/GS0y/xgsMP8WKi//FSkv/xQpMf8UKjX/Fiw6/xgvQf8aMkf/HTVO/yA4VP8hO1j/Izxa/wBdAKL/AAkkQ/8KJUP/DCdD/w8pQ/8SK0L/FC1B/xcvP/8YLzz/GC85/xcuNf8WLDL/FCow/xIoLv8RJy//ECcx/xAoNP8RKjr/FCxA/xYwR/8ZNE7/HDdT/x45WP8fO1r/AV0Aov8ACCRE/wklRP8LJ0T/DilE/xErQ/8TLUH/FS4//xYvPP8WLjn/FS01/xQrMv8SKS//ECcu/w4lLv8OJTD/DiY0/w8oOf8RKz//FC9G/xcyTf8aNlP/HDhY/x46Wv+bWU2VigR1DwAAAABJRU5ErkJggg==" alt="Example of a thumbhash placeholder decoded to a tiny PNG data URL" /></td>
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

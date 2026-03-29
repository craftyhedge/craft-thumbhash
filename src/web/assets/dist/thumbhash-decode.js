/**
 * ThumbHash Decoder
 *
 * Client-side decoder for ThumbHash placeholders.
 * Decoder logic from evanw/thumbhash (MIT License).
 * https://github.com/evanw/thumbhash
 *
 * Decodes data-thumbhash attributes into placeholder data URLs.
 * Image loading/swapping is left entirely to the developer or their lazy loading library.
 *
 * Usage:
 *   <img data-thumbhash="BASE64_HASH" data-src="real-image.jpg" alt="..." />
 *   <div data-thumbhash-bg="BASE64_HASH"></div>
 *
 * The script will:
 * 1. Decode the thumbhash to a tiny PNG data URL
 * 2. Set it as the src of the element or as background-image when data-thumbhash-bg is present
 * 3. Observe DOM mutations for dynamically added elements
 *
 * Your lazy loading library (lazysizes, lozad, etc.) handles swapping
 * data-src → src when the element enters the viewport.
 *
 * Also exposes window.thumbhash.toDataURL(base64Hash) for manual use.
 */
; (function () {
    'use strict';

    var config = window.thumbhashConfig || {};

    function getBackgroundPlaceholderStyles() {
        var configuredStyles = config.backgroundPlaceholderStyles;
        return configuredStyles && typeof configuredStyles === 'object' ? configuredStyles : {};
    }

    function applyInlineStyles(el, styles) {
        for (var property in styles) {
            if (!Object.prototype.hasOwnProperty.call(styles, property)) {
                continue;
            }

            var value = styles[property];
            if (typeof value !== 'string' || value === '') {
                continue;
            }

            el.style[property] = value;
        }
    }

    // ---- thumbHashToRGBA (from evanw/thumbhash, MIT) ----
    function thumbHashToRGBA(hash) {
        if (!hash || hash.length < 5) throw new Error('Invalid ThumbHash');
        var PI = Math.PI, min = Math.min, max = Math.max, cos = Math.cos, round = Math.round;

        // Read the constants
        var header24 = hash[0] | (hash[1] << 8) | (hash[2] << 16);
        var header16 = hash[3] | (hash[4] << 8);
        var l_dc = (header24 & 63) / 63;
        var p_dc = ((header24 >> 6) & 63) / 31.5 - 1;
        var q_dc = ((header24 >> 12) & 63) / 31.5 - 1;
        var l_scale = ((header24 >> 18) & 31) / 31;
        var hasAlpha = header24 >> 23;
        var p_scale = ((header16 >> 3) & 63) / 63;
        var q_scale = ((header16 >> 9) & 63) / 63;
        var isLandscape = header16 >> 15;
        var lx = max(3, isLandscape ? (hasAlpha ? 5 : 7) : (header16 & 7));
        var ly = max(3, isLandscape ? (header16 & 7) : (hasAlpha ? 5 : 7));
        var a_dc = hasAlpha ? (hash[5] & 15) / 15 : 1;
        var a_scale = (hash[5] >> 4) / 15;

        // Read the varying factors (boost saturation by 1.25x to compensate for quantization)
        var ac_start = hasAlpha ? 6 : 5;
        var ac_index = 0;

        var decodeChannel = function (nx, ny, scale) {
            var ac = [];
            for (var cy = 0; cy < ny; cy++) {
                for (var cx = cy ? 0 : 1; cx * ny < nx * (ny - cy); cx++) {
                    var idx = ac_start + (ac_index >> 1);
                    var nibble = (hash[idx] >> ((ac_index & 1) << 2)) & 15;
                    ac_index++;
                    ac.push((nibble / 7.5 - 1) * scale);
                }
            }
            return ac;
        };

        var l_ac = decodeChannel(lx, ly, l_scale);
        var p_ac = decodeChannel(3, 3, p_scale * 1.25);
        var q_ac = decodeChannel(3, 3, q_scale * 1.25);
        var a_ac = hasAlpha ? decodeChannel(5, 5, a_scale) : null;

        // Decode using the DCT into RGB
        var ratio = thumbHashToApproximateAspectRatio(hash);
        var w = round(ratio > 1 ? 32 : 32 * ratio);
        var h = round(ratio > 1 ? 32 / ratio : 32);
        var rgba = new Uint8Array(w * h * 4);
        var fx = [], fy = [];
        var n = max(lx, hasAlpha ? 5 : 3);
        var n2 = max(ly, hasAlpha ? 5 : 3);

        for (var y = 0, i = 0; y < h; y++) {
            // Precompute fy once per row (depends only on y)
            for (var cy = 0; cy < n2; cy++) {
                fy[cy] = cos(PI / h * (y + 0.5) * cy);
            }
            for (var x = 0; x < w; x++, i += 4) {
                var l = l_dc, p = p_dc, q = q_dc, a = a_dc;

                // Precompute fx per column
                for (var cx = 0; cx < n; cx++) {
                    fx[cx] = cos(PI / w * (x + 0.5) * cx);
                }

                // Decode L
                for (var cy = 0, j = 0; cy < ly; cy++) {
                    for (var cx = cy ? 0 : 1, fy2 = fy[cy] * 2; cx * ly < lx * (ly - cy); cx++, j++) {
                        l += l_ac[j] * fx[cx] * fy2;
                    }
                }

                // Decode P and Q
                for (var cy = 0, j = 0; cy < 3; cy++) {
                    for (var cx = cy ? 0 : 1, fy2 = fy[cy] * 2; cx < 3 - cy; cx++, j++) {
                        var f = fx[cx] * fy2;
                        p += p_ac[j] * f;
                        q += q_ac[j] * f;
                    }
                }

                // Decode A
                if (hasAlpha) {
                    for (var cy = 0, j = 0; cy < 5; cy++) {
                        for (var cx = cy ? 0 : 1, fy2 = fy[cy] * 2; cx < 5 - cy; cx++, j++) {
                            a += a_ac[j] * fx[cx] * fy2;
                        }
                    }
                }

                // Convert to RGB
                var b = l - 2 / 3 * p;
                var r = (3 * l - b + q) / 2;
                var g = r - q;
                rgba[i] = max(0, 255 * min(1, r));
                rgba[i + 1] = max(0, 255 * min(1, g));
                rgba[i + 2] = max(0, 255 * min(1, b));
                rgba[i + 3] = max(0, 255 * min(1, a));
            }
        }

        return { w: w, h: h, rgba: rgba };
    }

    function thumbHashToApproximateAspectRatio(hash) {
        var header = hash[3];
        var hasAlpha = hash[2] & 0x80;
        var isLandscape = hash[4] & 0x80;
        var lx = isLandscape ? (hasAlpha ? 5 : 7) : (header & 7);
        var ly = isLandscape ? (header & 7) : (hasAlpha ? 5 : 7);
        return lx / ly;
    }

    // ---- rgbaToDataURL (from evanw/thumbhash, MIT) ----
    function rgbaToDataURL(w, h, rgba) {
        var row = w * 4 + 1;
        var idat = 6 + h * (5 + row);
        var bytes = [
            137, 80, 78, 71, 13, 10, 26, 10, 0, 0, 0, 13, 73, 72, 68, 82, 0, 0,
            w >> 8, w & 255, 0, 0, h >> 8, h & 255, 8, 6, 0, 0, 0, 0, 0, 0, 0,
            idat >>> 24, (idat >> 16) & 255, (idat >> 8) & 255, idat & 255,
            73, 68, 65, 84, 120, 1
        ];
        var table = [
            0, 498536548, 997073096, 651767980, 1994146192, 1802195444, 1303535960,
            1342533948, -306674912, -267414716, -690576408, -882789492, -1687895376,
            -2032938284, -1609899400, -1111625188
        ];
        var a = 1, b = 0;
        for (var y = 0, i = 0, end = row - 1; y < h; y++, end += row - 1) {
            bytes.push(y + 1 < h ? 0 : 1, row & 255, row >> 8, ~row & 255, (row >> 8) ^ 255, 0);
            for (b = (b + a) % 65521; i < end; i++) {
                var u = rgba[i] & 255;
                bytes.push(u);
                a = (a + u) % 65521;
                b = (b + a) % 65521;
            }
        }
        bytes.push(
            b >> 8, b & 255, a >> 8, a & 255, 0, 0, 0, 0,
            0, 0, 0, 0, 73, 69, 78, 68, 174, 66, 96, 130
        );
        var pairs = [[12, 29], [37, 41 + idat]];
        for (var p = 0; p < pairs.length; p++) {
            var start = pairs[p][0], pend = pairs[p][1];
            var c = ~0;
            for (var i = start; i < pend; i++) {
                c ^= bytes[i];
                c = (c >>> 4) ^ table[c & 15];
                c = (c >>> 4) ^ table[c & 15];
            }
            c = ~c;
            bytes[pend++] = c >>> 24;
            bytes[pend++] = (c >> 16) & 255;
            bytes[pend++] = (c >> 8) & 255;
            bytes[pend++] = c & 255;
        }
        return 'data:image/png;base64,' + btoa(String.fromCharCode.apply(null, bytes));
    }

    // ---- ThumbHash to Data URL convenience ----
    function thumbHashToDataURL(hash) {
        var image = thumbHashToRGBA(hash);
        return rgbaToDataURL(image.w, image.h, image.rgba);
    }

    // ---- Base64 to Uint8Array ----
    function base64ToUint8Array(base64) {
        var binary = atob(base64);
        var bytes = new Uint8Array(binary.length);
        for (var i = 0; i < binary.length; i++) {
            bytes[i] = binary.charCodeAt(i);
        }
        return bytes;
    }

    // ---- Decode cache (avoids re-decoding same hash on multiple elements) ----
    var decodeCache = {};

    function cachedToDataURL(base64Hash) {
        if (decodeCache[base64Hash]) return decodeCache[base64Hash];
        var hashBytes = base64ToUint8Array(base64Hash);
        var dataUrl = thumbHashToDataURL(hashBytes);
        decodeCache[base64Hash] = dataUrl;
        return dataUrl;
    }

    // ---- Public API ----
    window.thumbhash = {
        toDataURL: function (base64Hash) {
            return cachedToDataURL(base64Hash);
        },
        toBackgroundImage: function (base64Hash) {
            return 'url("' + cachedToDataURL(base64Hash) + '")';
        },
    };

    function getThumbhashValue(el) {
        var hashStr = el.dataset.thumbhash;
        if (hashStr) {
            return hashStr;
        }

        var backgroundHashStr = el.getAttribute('data-thumbhash-bg');
        if (backgroundHashStr) {
            return backgroundHashStr;
        }

        return '';
    }

    function setElementPlaceholder(el, dataUrl) {
        if (el.hasAttribute('data-thumbhash-bg')) {
            el.style.backgroundImage = 'url("' + dataUrl + '")';
            applyInlineStyles(el, getBackgroundPlaceholderStyles());
            return;
        }

        if ('src' in el) {
            el.src = dataUrl;
        }
    }

    // ---- Process a single element ----
    function processElement(el) {
        if (el.dataset.thumbhashProcessed) return;

        var hashStr = getThumbhashValue(el);
        if (!hashStr) return;

        el.dataset.thumbhashProcessed = '1';

        try {
            var dataUrl = window.thumbhash.toDataURL(hashStr);

            // Set decoded placeholder as src or background-image (LQIP)
            setElementPlaceholder(el, dataUrl);
        } catch (e) {
            // Silently fail — don't break the page for a placeholder
        }
    }

    // ---- MutationObserver for dynamically added elements ----
    function observeDOM() {
        if (!('MutationObserver' in window)) return;

        var domObserver = new MutationObserver(function (mutations) {
            for (var i = 0; i < mutations.length; i++) {
                var added = mutations[i].addedNodes;
                for (var j = 0; j < added.length; j++) {
                    var node = added[j];
                    if (node.nodeType === 1) {
                        if (node.hasAttribute && (node.hasAttribute('data-thumbhash') || node.hasAttribute('data-thumbhash-bg'))) {
                            processElement(node);
                        }
                        if (node.querySelectorAll) {
                            var children = node.querySelectorAll('[data-thumbhash]:not([data-thumbhash-processed]), [data-thumbhash-bg]:not([data-thumbhash-processed])');
                            for (var k = 0; k < children.length; k++) {
                                processElement(children[k]);
                            }
                        }
                    }
                }
            }
        });

        domObserver.observe(document.documentElement, {
            childList: true,
            subtree: true,
        });
    }

    // ---- Boot ----
    initAll();
    observeDOM();
})();

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
 *   <picture data-thumbhash="BASE64_HASH">
 *     <source data-srcset="large.webp" type="image/webp" />
 *     <img data-src="fallback.jpg" alt="..." />
 *   </picture>
 *   <div data-thumbhash="BASE64_HASH" data-thumbhash-render="bg"></div>
 *
 * Render method resolution:
 * 1. Per-element: data-thumbhash-render="bg|picture|img"
 * 2. Global fallback: window.thumbhashConfig.renderMethod (default "bg")
 *
 * With render="picture", the hash is propagated to child <source> (srcset)
 * and <img> (src) elements inside the parent element.
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

    function isFiniteNumber(value) {
        return typeof value === 'number' && isFinite(value);
    }

    var PLACEHOLDER_LONG_SIDE = 32;

    function normalizePositiveNumber(value) {
        var numeric = Number(value);
        return isFiniteNumber(numeric) && numeric > 0 ? numeric : 0;
    }

    function getElementAspectRatio(el) {
        var width = normalizePositiveNumber(el.getAttribute('width'));
        var height = normalizePositiveNumber(el.getAttribute('height'));
        if (!width || !height) {
            return 0;
        }
        return width / height;
    }

    function getBackgroundAspectRatio(el) {
        var ratio = getElementAspectRatio(el);
        if (ratio) {
            return ratio;
        }

        if (!el.querySelectorAll) {
            return 0;
        }

        var images = el.querySelectorAll('img');
        for (var i = 0; i < images.length; i++) {
            ratio = getElementAspectRatio(images[i]);
            if (ratio) {
                return ratio;
            }
        }

        return 0;
    }

    function cropAndResizeRGBA(image, targetRatio) {
        if (!targetRatio) {
            return image;
        }

        var targetW = targetRatio >= 1
            ? PLACEHOLDER_LONG_SIDE
            : Math.max(1, Math.round(PLACEHOLDER_LONG_SIDE * targetRatio));
        var targetH = targetRatio >= 1
            ? Math.max(1, Math.round(PLACEHOLDER_LONG_SIDE / targetRatio))
            : PLACEHOLDER_LONG_SIDE;

        var sourceRatio = image.w / image.h;
        var cropX = 0;
        var cropY = 0;
        var cropW = image.w;
        var cropH = image.h;

        if (sourceRatio > targetRatio) {
            cropW = Math.max(1, Math.round(cropH * targetRatio));
            cropX = (image.w - cropW) / 2;
        } else if (sourceRatio < targetRatio) {
            cropH = Math.max(1, Math.round(cropW / targetRatio));
            cropY = (image.h - cropH) / 2;
        }

        var out = new Uint8Array(targetW * targetH * 4);
        for (var y = 0; y < targetH; y++) {
            var sy = Math.max(0, Math.min(image.h - 1, cropY + (y + 0.5) * cropH / targetH - 0.5));
            var y0 = Math.floor(sy);
            var y1 = Math.min(image.h - 1, y0 + 1);
            var wy = sy - y0;

            for (var x = 0; x < targetW; x++) {
                var sx = Math.max(0, Math.min(image.w - 1, cropX + (x + 0.5) * cropW / targetW - 0.5));
                var x0 = Math.floor(sx);
                var x1 = Math.min(image.w - 1, x0 + 1);
                var wx = sx - x0;

                var i00 = (y0 * image.w + x0) * 4;
                var i10 = (y0 * image.w + x1) * 4;
                var i01 = (y1 * image.w + x0) * 4;
                var i11 = (y1 * image.w + x1) * 4;
                var dstIndex = (y * targetW + x) * 4;

                for (var c = 0; c < 4; c++) {
                    var top = image.rgba[i00 + c] + (image.rgba[i10 + c] - image.rgba[i00 + c]) * wx;
                    var bottom = image.rgba[i01 + c] + (image.rgba[i11 + c] - image.rgba[i01 + c]) * wx;
                    out[dstIndex + c] = Math.round(top + (bottom - top) * wy);
                }
            }
        }

        return { w: targetW, h: targetH, rgba: out };
    }

    var crcTable = [];
    for (var n = 0; n < 256; n++) {
        var c = n;
        for (var k = 0; k < 8; k++) {
            c = (c & 1) ? (0xEDB88320 ^ (c >>> 1)) : (c >>> 1);
        }
        crcTable[n] = c >>> 0;
    }

    var PNG_ADLER32_MOD = 65521;

    function computeAdler32(bytes) {
        var a = 1;
        var b = 0;
        for (var i = 0; i < bytes.length; i++) {
            a = (a + bytes[i]) % PNG_ADLER32_MOD;
            b = (b + a) % PNG_ADLER32_MOD;
        }
        return ((b << 16) | a) >>> 0;
    }

    function writeCrc32(bytes, start, end) {
        var c = 0xFFFFFFFF;
        for (var i = start; i < end; i++) {
            c = crcTable[(c ^ bytes[i]) & 255] ^ (c >>> 8);
        }
        c = (c ^ 0xFFFFFFFF) >>> 0;
        bytes[end] = (c >>> 24) & 255;
        bytes[end + 1] = (c >>> 16) & 255;
        bytes[end + 2] = (c >>> 8) & 255;
        bytes[end + 3] = c & 255;
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
        var lx_raw = isLandscape ? (hasAlpha ? 5 : 7) : (header16 & 7);
        var ly_raw = isLandscape ? (header16 & 7) : (hasAlpha ? 5 : 7);
        var lx = max(3, lx_raw);
        var ly = max(3, ly_raw);
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
        var ratio = lx_raw / ly_raw;
        var w = round(ratio > 1 ? 32 : 32 * ratio);
        var h = round(ratio > 1 ? 32 / ratio : 32);
        var rgba = new Uint8Array(w * h * 4);
        var fx, fy = [];
        var n = max(lx, hasAlpha ? 5 : 3);
        var n2 = max(ly, hasAlpha ? 5 : 3);

        // Precompute fx table (depends only on x, reused across all rows)
        var fxTable = new Array(w);
        for (var x = 0; x < w; x++) {
            fxTable[x] = new Array(n);
            for (var cx = 0; cx < n; cx++) {
                fxTable[x][cx] = cos(PI / w * (x + 0.5) * cx);
            }
        }

        for (var y = 0, i = 0; y < h; y++) {
            // Precompute fy once per row (depends only on y)
            for (var cy = 0; cy < n2; cy++) {
                fy[cy] = cos(PI / h * (y + 0.5) * cy);
            }
            for (var x = 0; x < w; x++, i += 4) {
                var l = l_dc, p = p_dc, q = q_dc, a = a_dc;
                fx = fxTable[x];

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

    // ---- rgbaToDataURL (from evanw/thumbhash, MIT) ----
    function rgbaToDataURL(w, h, rgba) {
        var row = w * 4 + 1;
        var idat = 6 + h * (5 + row);

        // Build the uncompressed scanline payload once so Adler32 is exact.
        var raw = new Uint8Array(h * row);
        for (var y = 0, src = 0, dst = 0; y < h; y++) {
            raw[dst++] = 0; // PNG filter type 0 (None)
            for (var x = 0; x < w * 4; x++) {
                raw[dst++] = rgba[src++];
            }
        }

        var bytes = [
            137, 80, 78, 71, 13, 10, 26, 10, 0, 0, 0, 13, 73, 72, 68, 82, 0, 0,
            w >> 8, w & 255, 0, 0, h >> 8, h & 255, 8, 6, 0, 0, 0, 0, 0, 0, 0,
            idat >>> 24, (idat >> 16) & 255, (idat >> 8) & 255, idat & 255,
            73, 68, 65, 84, 120, 1
        ];

        for (var y = 0, rawOffset = 0; y < h; y++) {
            bytes.push(y + 1 < h ? 0 : 1, row & 255, row >> 8, ~row & 255, (row >> 8) ^ 255, 0);
            for (var i = 1; i < row; i++) {
                bytes.push(raw[rawOffset + i] & 255);
            }
            rawOffset += row;
        }

        var adler = computeAdler32(raw);
        bytes.push(
            (adler >>> 24) & 255, (adler >> 16) & 255, (adler >> 8) & 255, adler & 255, 0, 0, 0, 0,
            0, 0, 0, 0, 73, 69, 78, 68, 174, 66, 96, 130
        );

        writeCrc32(bytes, 12, 29); // IHDR type+data -> IHDR CRC
        writeCrc32(bytes, 37, 41 + idat); // IDAT type+data -> IDAT CRC

        return 'data:image/png;base64,' + btoa(String.fromCharCode.apply(null, bytes));
    }

    // ---- ThumbHash to Data URL convenience ----
    function thumbHashToDataURL(hash, targetRatio) {
        var image = thumbHashToRGBA(hash);
        var ratio = normalizePositiveNumber(targetRatio);
        if (ratio) {
            image = cropAndResizeRGBA(image, ratio);
        }
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
    var hashBytesCache = {};
    var decodeCache = {};

    function cachedHashBytes(base64Hash) {
        if (hashBytesCache[base64Hash]) return hashBytesCache[base64Hash];
        var hashBytes = base64ToUint8Array(base64Hash);
        hashBytesCache[base64Hash] = hashBytes;
        return hashBytes;
    }

    function decodeCacheKey(base64Hash, targetRatio) {
        var ratio = normalizePositiveNumber(targetRatio);
        return ratio ? base64Hash + '|' + ratio.toFixed(6) : base64Hash;
    }

    function cachedToDataURL(base64Hash, targetRatio) {
        var key = decodeCacheKey(base64Hash, targetRatio);
        if (decodeCache[key]) return decodeCache[key];
        var dataUrl = thumbHashToDataURL(cachedHashBytes(base64Hash), targetRatio);
        decodeCache[key] = dataUrl;
        return dataUrl;
    }

    // ---- Public API ----
    window.thumbhash = {
        toDataURL: function (base64Hash, targetRatio) {
            return cachedToDataURL(base64Hash, targetRatio);
        },
        toBackgroundImage: function (base64Hash) {
            return 'url("' + cachedToDataURL(base64Hash) + '")';
        },
    };

    // ---- Render method resolution ----
    function getRenderMethod(el) {
        var perElement = el.getAttribute('data-thumbhash-render');
        if (perElement) {
            return perElement;
        }
        return config.renderMethod || 'bg';
    }

    function applyBackground(el, dataUrl) {
        el.style.backgroundImage = 'url("' + dataUrl + '")';
        applyInlineStyles(el, getBackgroundPlaceholderStyles());
    }

    function applyChildPlaceholder(child, dataUrl) {
        if (child.tagName === 'SOURCE') {
            child.srcset = dataUrl;
        } else if ('src' in child) {
            child.src = dataUrl;
        }
    }

    // ---- Process a single element ----
    function processElement(el) {
        if (el.dataset.thumbhashReady) return;

        var hashStr = el.dataset.thumbhash;
        if (!hashStr) return;

        el.dataset.thumbhashReady = '1';

        try {
            var method = getRenderMethod(el);

            if (method === 'picture') {
                var children = el.querySelectorAll('source[data-srcset], img');
                for (var i = 0; i < children.length; i++) {
                    var child = children[i];
                    var ratio = getElementAspectRatio(child);
                    var dataUrl = window.thumbhash.toDataURL(hashStr, ratio);
                    applyChildPlaceholder(child, dataUrl);
                }
            } else if (method === 'img') {
                var ratio = getElementAspectRatio(el);
                var dataUrl = window.thumbhash.toDataURL(hashStr, ratio);
                applyChildPlaceholder(el, dataUrl);
            } else {
                var ratio = getBackgroundAspectRatio(el);
                var dataUrl = window.thumbhash.toDataURL(hashStr, ratio);
                applyBackground(el, dataUrl);
            }
        } catch (e) {
            // Silently fail — don't break the page for a placeholder
        }
    }

    function initAll() {
        var elements = document.querySelectorAll('[data-thumbhash]:not([data-thumbhash-ready])');
        for (var i = 0; i < elements.length; i++) {
            processElement(elements[i]);
        }
    }

    // ---- MutationObserver for dynamically added elements ----
    function observeDOM() {
        if (!('MutationObserver' in window)) return;

        var selector = '[data-thumbhash]:not([data-thumbhash-ready])';

        function handlePictureChild(node) {
            if (node.tagName !== 'SOURCE' && node.tagName !== 'IMG') return;
            var parent = node.parentElement;
            if (!parent) return;
            if (!parent.dataset.thumbhashReady) return;
            if (getRenderMethod(parent) !== 'picture') return;
            var hashStr = parent.dataset.thumbhash;
            if (!hashStr) return;
            if (node.tagName === 'SOURCE' && !node.hasAttribute('data-srcset')) return;
            var ratio = getElementAspectRatio(node);
            var dataUrl = window.thumbhash.toDataURL(hashStr, ratio);
            applyChildPlaceholder(node, dataUrl);
        }

        var domObserver = new MutationObserver(function (mutations) {
            for (var i = 0; i < mutations.length; i++) {
                var added = mutations[i].addedNodes;
                for (var j = 0; j < added.length; j++) {
                    var node = added[j];
                    if (node.nodeType !== 1) continue;

                    if (node.hasAttribute && node.hasAttribute('data-thumbhash')) {
                        processElement(node);
                    }

                    if (node.querySelectorAll) {
                        var matches = node.querySelectorAll(selector);
                        for (var k = 0; k < matches.length; k++) {
                            processElement(matches[k]);
                        }
                    }

                    // Handle source/img children arriving after their
                    // picture container was already processed.
                    handlePictureChild(node);
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

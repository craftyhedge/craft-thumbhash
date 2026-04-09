import { readFileSync } from 'fs';
import { resolve } from 'path';
import { beforeEach, describe, it, expect } from 'vitest';

const SCRIPT = readFileSync(
    resolve(process.env.DECODER_PATH ?? 'src/web/assets/dist/th-decoder.js'),
    'utf-8'
);

const KNOWN_HASH = 'nfcJFgTk2vhJiqaGZpdYlYVnqHCFCEY';

function getPngDimensionsFromDataUrl(dataUrl) {
    const base64 = dataUrl.replace(/^data:image\/png;base64,/, '');
    const bytes = Uint8Array.from(atob(base64), ch => ch.charCodeAt(0));
    const signature = Array.from(bytes.slice(0, 8));
    expect(signature).toEqual([137, 80, 78, 71, 13, 10, 26, 10]);

    const width =
        (bytes[16] << 24)
        | (bytes[17] << 16)
        | (bytes[18] << 8)
        | bytes[19];
    const height =
        (bytes[20] << 24)
        | (bytes[21] << 16)
        | (bytes[22] << 8)
        | bytes[23];

    return { width: width >>> 0, height: height >>> 0 };
}

// Default setup: boots the script with no pre-existing DOM or config.
// DOM boot tests use their own nested describe — see below.
beforeEach(() => {
    delete window.thumbhash;
    delete window.thumbhashConfig;
    document.body.innerHTML = '';
    eval(SCRIPT);
});

// ---------------------------------------------------------------------------
// Public API
// ---------------------------------------------------------------------------

describe('toDataURL', () => {
    it('returns a PNG data URL', () => {
        const url = window.thumbhash.toDataURL(KNOWN_HASH);
        expect(url).toMatch(/^data:image\/png;base64,/);
    });

    it('accepts a target ratio without throwing', () => {
        expect(() => window.thumbhash.toDataURL(KNOWN_HASH, 16 / 9)).not.toThrow();
    });

    it('applies target ratio to output PNG dimensions', () => {
        const url = window.thumbhash.toDataURL(KNOWN_HASH, 16 / 9);
        const { width, height } = getPngDimensionsFromDataUrl(url);
        expect(width).toBe(32);
        expect(height).toBe(18);
    });

    it('throws on an invalid hash', () => {
        expect(() => window.thumbhash.toDataURL('bad')).toThrow();
    });

    it('throws on malformed but base64-valid hash bytes', () => {
        // Base64-valid but structurally invalid/truncated ThumbHash payload.
        expect(() => window.thumbhash.toDataURL('AAAAAAA=')).toThrow();
    });

    it('returns PNG dimensions with positive width and height', () => {
        const url = window.thumbhash.toDataURL(KNOWN_HASH);
        const { width, height } = getPngDimensionsFromDataUrl(url);
        expect(width).toBeGreaterThan(0);
        expect(height).toBeGreaterThan(0);
    });

    it('caches: second call does not re-encode', () => {
        const origBtoa = window.btoa;
        let btoaCalls = 0;
        window.btoa = (...args) => { btoaCalls++; return origBtoa(...args); };
        try {
            // Re-eval boots a fresh closure with an empty decodeCache.
            eval(SCRIPT);
            btoaCalls = 0;
            window.thumbhash.toDataURL(KNOWN_HASH);
            const firstCalls = btoaCalls;
            btoaCalls = 0;
            window.thumbhash.toDataURL(KNOWN_HASH);
            expect(firstCalls).toBeGreaterThan(0);
            expect(btoaCalls).toBe(0);
        } finally {
            window.btoa = origBtoa;
        }
    });
});

describe('toBackgroundImage', () => {
    it('returns a CSS url() wrapping a PNG data URL', () => {
        const bg = window.thumbhash.toBackgroundImage(KNOWN_HASH);
        expect(bg).toMatch(/^url\("data:image\/png;base64,/);
    });
});

// ---------------------------------------------------------------------------
// DOM boot (elements present before script runs)
// Each test populates the DOM then evals so initAll() sees the elements.
// ---------------------------------------------------------------------------

describe('DOM boot', () => {
    beforeEach(() => {
        delete window.thumbhash;
        delete window.thumbhashConfig;
        document.body.innerHTML = '';
    });

    it('applies backgroundImage and sets data-thumbhash-ready for default bg render', () => {
        document.body.innerHTML = `<div data-thumbhash="${KNOWN_HASH}"></div>`;
        eval(SCRIPT);
        const el = document.querySelector('[data-thumbhash]');
        expect(el.dataset.thumbhashReady).toBe('1');
        expect(el.style.backgroundImage).toMatch(/^url\(/);
    });

    it('sets data-thumbhash-ready even when decoding fails (invalid hash)', () => {
        document.body.innerHTML = `<div data-thumbhash="!!!invalid!!!"></div>`;
        eval(SCRIPT);
        const el = document.querySelector('[data-thumbhash]');
        expect(el.dataset.thumbhashReady).toBe('1');
    });

    it('img render method sets src on the element', () => {
        document.body.innerHTML = `<img data-thumbhash="${KNOWN_HASH}" data-thumbhash-render="img">`;
        eval(SCRIPT);
        const el = document.querySelector('[data-thumbhash]');
        expect(el.src).toMatch(/^data:image\/png/);
    });

    it('picture render: <source data-srcset> gets srcset and <img> gets src', () => {
        document.body.innerHTML = `
            <div data-thumbhash="${KNOWN_HASH}" data-thumbhash-render="picture">
                <source data-srcset="">
                <img>
            </div>`;
        eval(SCRIPT);
        const source = document.querySelector('source');
        const img = document.querySelector('img');
        expect(source.srcset).toMatch(/^data:image\/png/);
        expect(img.src).toMatch(/^data:image\/png/);
    });

    it('picture render: <source> without data-srcset is skipped', () => {
        document.body.innerHTML = `
            <div data-thumbhash="${KNOWN_HASH}" data-thumbhash-render="picture">
                <source type="image/webp">
                <img>
            </div>`;
        eval(SCRIPT);
        const source = document.querySelector('source');
        expect(source.srcset).toBe('');
    });

    it('skips an element that already has data-thumbhash-ready set', () => {
        document.body.innerHTML = `<div data-thumbhash="${KNOWN_HASH}" data-thumbhash-ready="1"></div>`;
        eval(SCRIPT);
        const el = document.querySelector('[data-thumbhash]');
        expect(el.style.backgroundImage).toBe('');
    });

    it('skips an element with an empty data-thumbhash attribute', () => {
        document.body.innerHTML = `<div data-thumbhash=""></div>`;
        eval(SCRIPT);
        const el = document.querySelector('[data-thumbhash]');
        expect(el.dataset.thumbhashReady).toBeUndefined();
    });

    it('bg render reads aspect ratio from child <img width height>', () => {
        // Set up two identical hashes: one with a dimensioned child img (should crop),
        // one without (no crop). The resulting data URLs must differ — if getBackgroundAspectRatio
        // ignored child img dims, both would produce the same uncropped output.
        document.body.innerHTML = `
            <div id="with-ratio" data-thumbhash="${KNOWN_HASH}">
                <img width="16" height="9">
            </div>
            <div id="no-ratio" data-thumbhash="${KNOWN_HASH}"></div>`;
        eval(SCRIPT);
        const withRatio = document.getElementById('with-ratio');
        const noRatio = document.getElementById('no-ratio');
        expect(withRatio.style.backgroundImage).toMatch(/^url\(/);
        expect(noRatio.style.backgroundImage).toMatch(/^url\(/);
        expect(withRatio.style.backgroundImage).not.toBe(noRatio.style.backgroundImage);
    });
});

// ---------------------------------------------------------------------------
// Global config
// Config is captured at boot, so set window.thumbhashConfig before eval.
// ---------------------------------------------------------------------------

describe('global config', () => {
    beforeEach(() => {
        delete window.thumbhash;
        delete window.thumbhashConfig;
        document.body.innerHTML = '';
    });

    it('renderMethod: img is respected', () => {
        window.thumbhashConfig = { renderMethod: 'img' };
        document.body.innerHTML = `<img data-thumbhash="${KNOWN_HASH}">`;
        eval(SCRIPT);
        const el = document.querySelector('[data-thumbhash]');
        expect(el.src).toMatch(/^data:image\/png/);
        expect(el.style.backgroundImage).toBe('');
    });

    it('backgroundPlaceholderStyles: valid string values are applied', () => {
        window.thumbhashConfig = { backgroundPlaceholderStyles: { display: 'block' } };
        document.body.innerHTML = `<div data-thumbhash="${KNOWN_HASH}"></div>`;
        eval(SCRIPT);
        const el = document.querySelector('[data-thumbhash]');
        expect(el.style.display).toBe('block');
    });

    it('backgroundPlaceholderStyles: non-string values are ignored', () => {
        window.thumbhashConfig = { backgroundPlaceholderStyles: { opacity: 0 } };
        document.body.innerHTML = `<div data-thumbhash="${KNOWN_HASH}"></div>`;
        eval(SCRIPT);
        const el = document.querySelector('[data-thumbhash]');
        expect(el.style.opacity).toBe('');
    });

    it('backgroundPlaceholderStyles: empty string values are ignored', () => {
        window.thumbhashConfig = { backgroundPlaceholderStyles: { display: '' } };
        document.body.innerHTML = `<div data-thumbhash="${KNOWN_HASH}"></div>`;
        eval(SCRIPT);
        const el = document.querySelector('[data-thumbhash]');
        expect(el.style.display).toBe('');
    });
});

// ---------------------------------------------------------------------------
// MutationObserver (elements added after boot)
// The outer beforeEach boots the script with an empty DOM.
// ---------------------------------------------------------------------------

describe('MutationObserver', () => {
    it('processes a dynamically added element', async () => {
        const div = document.createElement('div');
        div.dataset.thumbhash = KNOWN_HASH;
        document.body.appendChild(div);
        await new Promise(r => setTimeout(r, 0));
        expect(div.style.backgroundImage).toMatch(/^url\(/);
    });

    it('late-arriving <source data-srcset> in an already-processed <picture> gets srcset', async () => {
        document.body.innerHTML = `
            <div
                data-thumbhash="${KNOWN_HASH}"
                data-thumbhash-render="picture"
                data-thumbhash-ready="1"
            ></div>`;
        const picture = document.querySelector('[data-thumbhash]');
        const source = document.createElement('source');
        source.setAttribute('data-srcset', '');
        picture.appendChild(source);
        await new Promise(r => setTimeout(r, 0));
        expect(source.srcset).toMatch(/^data:image\/png/);
    });

    it('late-arriving <source> without data-srcset is ignored', async () => {
        document.body.innerHTML = `
            <div
                data-thumbhash="${KNOWN_HASH}"
                data-thumbhash-render="picture"
                data-thumbhash-ready="1"
            ></div>`;
        const picture = document.querySelector('[data-thumbhash]');
        const source = document.createElement('source');
        picture.appendChild(source);
        await new Promise(r => setTimeout(r, 0));
        expect(source.srcset).toBe('');
    });

    it('late-arriving <img> in an already-processed <picture> gets src', async () => {
        document.body.innerHTML = `
            <div
                data-thumbhash="${KNOWN_HASH}"
                data-thumbhash-render="picture"
                data-thumbhash-ready="1"
            ></div>`;
        const picture = document.querySelector('[data-thumbhash]');
        const img = document.createElement('img');
        picture.appendChild(img);
        await new Promise(r => setTimeout(r, 0));
        expect(img.src).toMatch(/^data:image\/png/);
    });
});

/**
 * HEIC/HEIF to JPEG in the browser, so uploads work even when the server has
 * no HEIC support (common on shared hosting).
 *
 * 1. The browser's own decoder (Safari on iOS/macOS), via canvas: fast and no
 *    download. Other browsers reject right away.
 * 2. Fallback: heic-to (libheif compiled to WebAssembly, ~3 MB), loaded only
 *    when native decoding fails.
 * Throws when neither works; the caller then uploads the original.
 * The result is limited to MAX_SIDE (see downscale.js): it is drawn straight
 * at that size, since a full-size canvas of a 48 MP photo fails on iOS.
 */

import { downscaleIfNeeded, encodeBitmap } from './downscale';

const QUALITY = 0.92;
const TIMEOUT_MS = 90_000;
const NATIVE_TIMEOUT_MS = 30_000;

function jpegName(name) {
    return name.replace(/\.(heic|heif)$/i, '') + '.jpg';
}

function withTimeout(promise, ms = TIMEOUT_MS) {
    let timer;
    return Promise.race([promise, new Promise((_, reject) => (timer = setTimeout(() => reject(new Error('timeout')), ms)))]).finally(() => clearTimeout(timer));
}

async function viaLibheif(file) {
    const { heicTo } = await import('heic-to/csp'); // CSP-safe build (no string eval)
    return heicTo({ blob: file, type: 'image/jpeg', quality: QUALITY });
}

/** Decodes with an <img> element (some Safari versions only decode HEIC there, not in createImageBitmap). */
async function viaImageElement(file) {
    const url = URL.createObjectURL(file);
    try {
        const img = new Image();
        img.decoding = 'async';
        img.src = url;
        await img.decode();
        if (!img.naturalWidth) throw new Error('decode');
        // encodeBitmap reads width/height; an <img> outside the DOM reports its natural size.
        return await encodeBitmap(img, 'image/jpeg');
    } finally {
        URL.revokeObjectURL(url);
    }
}

async function viaBrowser(file) {
    if (typeof createImageBitmap === 'function') {
        try {
            return await encodeBitmap(await createImageBitmap(file, { imageOrientation: 'from-image' }), 'image/jpeg');
        } catch {
            // Try the <img> decoder below.
        }
    }
    return viaImageElement(file);
}

export async function convertHeicToJpeg(file) {
    let blob = null;
    try {
        blob = await withTimeout(viaBrowser(file), NATIVE_TIMEOUT_MS);
    } catch {
        blob = null;
    }
    if (!blob || blob.size === 0) {
        blob = await withTimeout(viaLibheif(file));
    }

    if (!blob || blob.size === 0) throw new Error('empty');

    return downscaleIfNeeded(new File([blob], jpegName(file.name), { type: 'image/jpeg', lastModified: file.lastModified }));
}

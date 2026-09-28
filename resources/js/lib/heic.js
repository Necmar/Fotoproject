/**
 * HEIC/HEIF to JPEG in the browser, so uploads work even when the server has
 * no HEIC support (common on shared hosting).
 *
 * 1. heic-to (libheif compiled to WebAssembly), loaded only when needed.
 * 2. Fallback: the browser's own decoder (Safari on iOS/macOS), via canvas.
 * Throws when neither works; the caller then uploads the original.
 * The result is limited to MAX_SIDE (see downscale.js).
 */

import { downscaleIfNeeded, encodeBitmap } from './downscale';

const QUALITY = 0.92;
const TIMEOUT_MS = 90_000;

function jpegName(name) {
    return name.replace(/\.(heic|heif)$/i, '') + '.jpg';
}

function withTimeout(promise) {
    return Promise.race([promise, new Promise((_, reject) => setTimeout(() => reject(new Error('timeout')), TIMEOUT_MS))]);
}

async function viaLibheif(file) {
    const { heicTo } = await import('heic-to/csp'); // CSP-safe build (no string eval)
    return heicTo({ blob: file, type: 'image/jpeg', quality: QUALITY });
}

async function viaBrowser(file) {
    // Drawn straight at MAX_SIDE: a full-size canvas of a 48 MP photo fails on iOS.
    return encodeBitmap(await createImageBitmap(file, { imageOrientation: 'from-image' }), 'image/jpeg');
}

export async function convertHeicToJpeg(file) {
    let blob;
    try {
        blob = await withTimeout(viaLibheif(file));
    } catch {
        blob = await withTimeout(viaBrowser(file));
    }

    if (!blob || blob.size === 0) throw new Error('empty');

    return downscaleIfNeeded(new File([blob], jpegName(file.name), { type: 'image/jpeg', lastModified: file.lastModified }));
}

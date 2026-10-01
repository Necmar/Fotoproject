/**
 * Client-side size reduction before upload.
 *
 * Phones take photos of 12 to 48 megapixels. The largest output is 2560 px, so
 * anything above MAX_SIDE only costs upload time on mobile data, disk space and
 * PHP memory on shared hosting (GD needs ~5 bytes per pixel). Larger photos are
 * scaled down to MAX_SIDE here; smaller ones are sent untouched. Scaling also
 * applies the EXIF orientation, which drops the metadata (incl. GPS) with it.
 *
 * iOS Safari refuses canvases above ~16.7 million pixels, so a full-size canvas
 * of a 48 MP photo fails there; drawing straight to the target size avoids that.
 */

export const MAX_SIDE = 2560; // the largest output; more only costs upload time and disk space

/** Files below this size are never decoded here: they cannot be much larger than MAX_SIDE. */
export const PREPARE_MIN_BYTES = 1.5 * 1024 * 1024;

const JPEG_QUALITY = 0.92;

export function targetSize(width, height, maxSide = MAX_SIDE) {
    const longest = Math.max(width, height);
    if (longest <= maxSide) return null;
    const scale = maxSide / longest;
    return { width: Math.max(1, Math.round(width * scale)), height: Math.max(1, Math.round(height * scale)) };
}

/** Draws a decoded bitmap at (at most) MAX_SIDE and encodes it. Closes the bitmap. */
export async function encodeBitmap(bitmap, type = 'image/jpeg') {
    const size = targetSize(bitmap.width, bitmap.height) ?? { width: bitmap.width, height: bitmap.height };
    const canvas = document.createElement('canvas');
    canvas.width = size.width;
    canvas.height = size.height;
    const ctx = canvas.getContext('2d');
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(bitmap, 0, 0, size.width, size.height);
    bitmap.close?.();

    const blob = await new Promise((resolve, reject) =>
        canvas.toBlob((b) => (b ? resolve(b) : reject(new Error('encode'))), type, type === 'image/jpeg' ? JPEG_QUALITY : undefined),
    );
    canvas.width = canvas.height = 0; // frees the backing store right away on iOS

    return blob;
}

/**
 * Returns a smaller copy when the photo is larger than MAX_SIDE, otherwise the
 * file itself. Never throws: when the browser cannot decode it, the server decides.
 */
export async function downscaleIfNeeded(file) {
    if (file.size < PREPARE_MIN_BYTES || !['image/jpeg', 'image/png'].includes(file.type)) return file;

    try {
        const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
        if (!targetSize(bitmap.width, bitmap.height)) {
            bitmap.close?.();
            return file;
        }

        const blob = await encodeBitmap(bitmap, file.type);
        if (!blob.size || blob.size >= file.size) return file;

        return new File([blob], file.name, { type: file.type, lastModified: file.lastModified });
    } catch {
        return file;
    }
}

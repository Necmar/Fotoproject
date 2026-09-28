import { useCallback, useEffect, useRef, useState } from 'react';
import api, { errorMessage } from './api';
import i18n from './i18n';
import { convertHeicToJpeg } from './heic';

const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'heic', 'heif'];
const ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence'];
const UPLOAD_CONCURRENCY = 2;

let counter = 0;
const nextId = () => `local-${Date.now()}-${++counter}`;

export function extensionOf(name) {
    return (name.split('.').pop() || '').toLowerCase();
}

export function isHeif(file) {
    return ['heic', 'heif'].includes(extensionOf(file.name)) || /hei[cf]/.test(file.type);
}

/**
 * Client-side upload queue for one batch.
 *
 * 1. Validates type, size and count before sending (the server checks again).
 * 2. Converts HEIC/HEIF to JPEG in the browser, one at a time (phones have
 *    little memory). If that fails the original is uploaded and the server
 *    tries; if the server cannot either, the photo shows "conversie mislukt".
 * 3. Uploads one file per request, two at a time, with progress per file.
 *
 * Item: { id, file, name, size, previewUrl, status, progress, error, needsConversion, retryable }
 * status: 'pending' | 'converting' | 'uploading' | 'error'
 * Uploaded files leave the queue and are passed to onUploaded(image).
 */
export function useUploadQueue({ batchId, maxFiles, maxMb, existingCount, onUploaded }) {
    const [items, setItems] = useState([]);
    const [notice, setNotice] = useState(null);
    const uploading = useRef(new Set());
    const converting = useRef(null);
    const itemsRef = useRef(items);
    const onUploadedRef = useRef(onUploaded);

    itemsRef.current = items;
    onUploadedRef.current = onUploaded;

    const update = (id, patch) => setItems((list) => list.map((item) => (item.id === id ? { ...item, ...patch } : item)));
    const kick = () => setItems((list) => [...list]);

    const convert = useCallback(async (item) => {
        converting.current = item.id;
        update(item.id, { status: 'converting' });

        try {
            const jpeg = await convertHeicToJpeg(item.file);
            if (item.previewUrl) URL.revokeObjectURL(item.previewUrl);
            update(item.id, {
                file: jpeg,
                name: jpeg.name,
                size: jpeg.size,
                previewUrl: URL.createObjectURL(jpeg),
                needsConversion: false,
                status: jpeg.size > maxMb * 1024 * 1024 ? 'error' : 'pending',
                error: jpeg.size > maxMb * 1024 * 1024 ? i18n.t('upload.errors.too_large', { max: maxMb }) : null,
            });
        } catch {
            // Let the server try (Imagick/CLI). Its answer decides.
            update(item.id, { needsConversion: false, status: 'pending' });
        } finally {
            converting.current = null;
            kick();
        }
    }, [maxMb]);

    const upload = useCallback(
        async (item) => {
            uploading.current.add(item.id);
            update(item.id, { status: 'uploading', progress: 0, error: null });

            const body = new FormData();
            body.append('file', item.file, item.name);

            try {
                const { data } = await api.post(`/company/batches/${batchId}/images`, body, {
                    onUploadProgress: (e) => e.total && update(item.id, { progress: Math.round((e.loaded / e.total) * 100) }),
                });
                if (item.previewUrl) URL.revokeObjectURL(item.previewUrl);
                setItems((list) => list.filter((i) => i.id !== item.id));
                onUploadedRef.current?.(data.data, item);
            } catch (err) {
                const code = err.response?.data?.code;
                update(item.id, {
                    status: 'error',
                    error: err.response?.data?.errors?.file?.[0] ?? errorMessage(err),
                    // Invalid, damaged or unconvertible files will not succeed on retry.
                    retryable: !['invalid_type', 'corrupt_file', 'conversion_failed', 'file_too_large', 'too_many_pixels'].includes(code),
                });
            } finally {
                uploading.current.delete(item.id);
                kick();
            }
        },
        [batchId],
    );

    // Scheduler: one conversion at a time, two uploads at a time.
    useEffect(() => {
        if (!batchId) return;

        if (!converting.current) {
            const next = items.find((i) => i.status === 'pending' && i.needsConversion);
            if (next) convert(next);
        }

        const free = UPLOAD_CONCURRENCY - uploading.current.size;
        items
            .filter((i) => i.status === 'pending' && !i.needsConversion && !uploading.current.has(i.id))
            .slice(0, Math.max(0, free))
            .forEach(upload);
    }, [items, batchId, upload, convert]);

    const add = useCallback(
        (fileList) => {
            const files = Array.from(fileList ?? []);
            if (!files.length) return;

            const queued = itemsRef.current.filter((i) => i.status !== 'error').length;
            const room = Math.max(0, maxFiles - existingCount - queued);
            const accepted = [];
            let rejected = 0;
            let valid = 0;

            files.forEach((file) => {
                const heif = isHeif(file);
                const typeOk = ALLOWED_TYPES.includes(file.type) || ALLOWED_EXTENSIONS.includes(extensionOf(file.name));
                let error = null;

                if (!typeOk) error = i18n.t('upload.errors.invalid_type');
                // HEIC is checked after conversion (the JPEG is usually larger).
                else if (!heif && file.size > maxMb * 1024 * 1024) error = i18n.t('upload.errors.too_large', { max: maxMb });
                else if (file.size === 0) error = i18n.t('upload.errors.empty');

                if (!error) {
                    if (valid >= room) {
                        rejected += 1;
                        return;
                    }
                    valid += 1;
                }

                accepted.push({
                    id: nextId(),
                    file,
                    name: file.name,
                    size: file.size,
                    // Only Safari can show HEIC; others get a placeholder until converted.
                    previewUrl: !error && !heif ? URL.createObjectURL(file) : null,
                    status: error ? 'error' : 'pending',
                    needsConversion: !error && heif,
                    progress: 0,
                    error,
                    retryable: false,
                });
            });

            setNotice(rejected ? i18n.t('upload.errors.too_many', { max: maxFiles, count: rejected }) : null);
            setItems((list) => [...list, ...accepted]);
        },
        [maxFiles, maxMb, existingCount],
    );

    const retry = (id) => update(id, { status: 'pending', error: null, progress: 0 });

    const remove = (id) =>
        setItems((list) => {
            const item = list.find((i) => i.id === id);
            if (item?.previewUrl) URL.revokeObjectURL(item.previewUrl);
            return list.filter((i) => i.id !== id);
        });

    useEffect(() => () => itemsRef.current.forEach((i) => i.previewUrl && URL.revokeObjectURL(i.previewUrl)), []);

    const busy = items.some((i) => ['pending', 'converting', 'uploading'].includes(i.status));

    return { items, add, retry, remove, busy, notice, clearNotice: () => setNotice(null) };
}

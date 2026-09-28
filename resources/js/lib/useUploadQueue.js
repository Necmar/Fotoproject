import { useCallback, useEffect, useRef, useState } from 'react';
import api, { errorMessage } from './api';
import i18n from './i18n';

const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'heic', 'heif'];
const ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence'];
const CONCURRENCY = 2;

let counter = 0;
const nextId = () => `local-${Date.now()}-${++counter}`;

export function extensionOf(name) {
    return (name.split('.').pop() || '').toLowerCase();
}

export function isHeif(file) {
    return ['heic', 'heif'].includes(extensionOf(file.name)) || /hei[cf]/.test(file.type);
}

/**
 * Client-side upload queue for one batch. Validates type, size and count
 * before sending (the server validates again), uploads one file per request
 * with limited concurrency, and reports progress per file.
 *
 * Item: { id, file, name, size, previewUrl, status: 'pending'|'uploading'|'error', progress, error }
 * Successfully uploaded files leave the queue and are passed to onUploaded(image).
 */
export function useUploadQueue({ batchId, maxFiles, maxMb, existingCount, onUploaded }) {
    const [items, setItems] = useState([]);
    const [notice, setNotice] = useState(null);
    const active = useRef(0);
    const started = useRef(new Set());
    const itemsRef = useRef(items);
    const onUploadedRef = useRef(onUploaded);

    itemsRef.current = items;
    onUploadedRef.current = onUploaded;

    const update = (id, patch) => setItems((list) => list.map((item) => (item.id === id ? { ...item, ...patch } : item)));

    const upload = useCallback(
        async (item) => {
            active.current += 1;
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
                update(item.id, { status: 'error', error: err.response?.data?.errors?.file?.[0] ?? errorMessage(err) });
            } finally {
                active.current -= 1;
                started.current.delete(item.id);
                // Trigger the scheduler for the next pending file.
                setItems((list) => [...list]);
            }
        },
        [batchId],
    );

    // Start pending uploads whenever a slot is free.
    useEffect(() => {
        if (!batchId) return;
        const pending = items.filter((i) => i.status === 'pending' && !started.current.has(i.id));
        const free = CONCURRENCY - active.current;
        pending.slice(0, Math.max(0, free)).forEach((item) => {
            started.current.add(item.id); // guard against double starts between renders
            upload(item);
        });
    }, [items, batchId, upload]);

    const add = useCallback(
        (fileList) => {
            const files = Array.from(fileList ?? []);
            if (!files.length) return;

            const queued = itemsRef.current.filter((i) => i.status !== 'error').length;
            const room = Math.max(0, maxFiles - existingCount - queued);
            const accepted = [];
            const rejected = [];

            let valid = 0;

            files.forEach((file) => {
                const ext = extensionOf(file.name);
                const typeOk = ALLOWED_TYPES.includes(file.type) || ALLOWED_EXTENSIONS.includes(ext);
                let error = null;

                if (!typeOk) error = i18n.t('upload.errors.invalid_type');
                else if (file.size > maxMb * 1024 * 1024) error = i18n.t('upload.errors.too_large', { max: maxMb });
                else if (file.size === 0) error = i18n.t('upload.errors.empty');

                if (!error) {
                    if (valid >= room) {
                        rejected.push(file);
                        return;
                    }
                    valid += 1;
                }

                accepted.push({
                    id: nextId(),
                    file,
                    name: file.name,
                    size: file.size,
                    // Browsers other than Safari cannot render HEIC; show a placeholder instead.
                    previewUrl: !error && !isHeif(file) ? URL.createObjectURL(file) : null,
                    status: error ? 'error' : 'pending',
                    progress: 0,
                    error,
                    retryable: !error,
                });
            });

            setNotice(rejected.length ? i18n.t('upload.errors.too_many', { max: maxFiles, count: rejected.length }) : null);
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

    useEffect(
        () => () => itemsRef.current.forEach((i) => i.previewUrl && URL.revokeObjectURL(i.previewUrl)),
        [],
    );

    const busy = items.some((i) => i.status === 'pending' || i.status === 'uploading');

    return { items, add, retry, remove, busy, notice, clearNotice: () => setNotice(null) };
}

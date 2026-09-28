import { useTranslation } from 'react-i18next';
import { AlertTriangle, CheckCircle2, ImageIcon, Loader2, RotateCcw, Trash2, XCircle } from 'lucide-react';
import { formatBytes } from '../../lib/format';
import { Badge, cx } from '../ui';

const STATUS_TONE = {
    uploaded: 'grey',
    queued: 'grey',
    analyzing: 'brand',
    processing: 'brand',
    finalizing: 'brand',
    completed: 'green',
    failed: 'red',
};

export function ImageStatusBadge({ status }) {
    const { t } = useTranslation();
    return <Badge tone={STATUS_TONE[status] ?? 'grey'}>{t(`enums.image_status.${status}`)}</Badge>;
}

function Thumb({ src, alt, overlay }) {
    return (
        <div className="relative aspect-[4/3] overflow-hidden rounded-t-2xl bg-stone-100">
            {src ? (
                <img src={src} alt={alt} loading="lazy" decoding="async" className="size-full object-cover" />
            ) : (
                <div className="grid size-full place-items-center text-stone-400">
                    <div className="flex flex-col items-center gap-1">
                        <ImageIcon className="size-7" aria-hidden />
                        <span className="text-xs font-medium uppercase">{alt.split('.').pop()}</span>
                    </div>
                </div>
            )}
            {overlay}
        </div>
    );
}

/** A photo that still lives in the browser: waiting, uploading or rejected. */
export function LocalPhotoTile({ item, onRetry, onRemove }) {
    const { t, i18n } = useTranslation();
    const uploading = item.status === 'uploading' || item.status === 'pending';

    return (
        <div className={cx('rounded-2xl bg-white ring-1', item.status === 'error' ? 'ring-red-200' : 'ring-stone-200/80')}>
            <Thumb
                src={item.previewUrl}
                alt={item.name}
                overlay={
                    uploading && (
                        <div className="absolute inset-x-0 bottom-0 h-1.5 bg-stone-900/10">
                            <div className="h-full bg-brand-600 transition-all" style={{ width: `${item.progress}%` }} />
                        </div>
                    )
                }
            />
            <div className="space-y-1.5 p-3">
                <p className="truncate text-sm font-medium" title={item.name}>
                    {item.name}
                </p>
                <div className="flex items-center justify-between gap-2 text-xs text-stone-500">
                    <span>{formatBytes(item.size, i18n.language)}</span>
                    {item.status === 'error' ? (
                        <span className="inline-flex items-center gap-1 text-red-600">
                            <XCircle className="size-3.5" aria-hidden /> {t('upload.status.error')}
                        </span>
                    ) : (
                        <span className="inline-flex items-center gap-1">
                            <Loader2 className="size-3.5 animate-spin" aria-hidden />
                            {item.status === 'pending' ? t('upload.status.pending') : `${item.progress}%`}
                        </span>
                    )}
                </div>
                {item.error && <p className="text-xs text-red-600">{item.error}</p>}
                {item.status === 'error' && (
                    <div className="flex gap-1 pt-1">
                        {item.retryable && (
                            <button type="button" onClick={() => onRetry(item.id)} className="inline-flex h-9 items-center gap-1 rounded-lg px-2 text-xs font-medium text-stone-700 hover:bg-stone-100">
                                <RotateCcw className="size-3.5" aria-hidden /> {t('upload.retry')}
                            </button>
                        )}
                        <button type="button" onClick={() => onRemove(item.id)} className="inline-flex h-9 items-center gap-1 rounded-lg px-2 text-xs font-medium text-stone-500 hover:bg-stone-100">
                            <Trash2 className="size-3.5" aria-hidden /> {t('upload.dismiss')}
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
}

/** A photo stored on the server. */
export function ServerPhotoTile({ image, onRemove, removing, showStatus = false }) {
    const { t, i18n } = useTranslation();
    const warnings = image.warnings ?? [];

    return (
        <div className="rounded-2xl bg-white ring-1 ring-stone-200/80">
            <Thumb src={image.urls.optimized ?? image.urls.thumbnail} alt={image.original_filename} />
            <div className="space-y-1.5 p-3">
                <div className="flex items-start justify-between gap-2">
                    <p className="truncate text-sm font-medium" title={image.original_filename}>
                        <span className="text-stone-400">{image.position}.</span> {image.original_filename}
                    </p>
                    {onRemove && (
                        <button
                            type="button"
                            onClick={() => onRemove(image)}
                            disabled={removing}
                            className="-m-1 rounded-lg p-2 text-stone-400 hover:bg-stone-100 hover:text-red-600"
                            aria-label={t('upload.remove')}
                        >
                            {removing ? <Loader2 className="size-4 animate-spin" /> : <Trash2 className="size-4" />}
                        </button>
                    )}
                </div>
                <div className="flex items-center justify-between gap-2 text-xs text-stone-500">
                    <span>{formatBytes(image.original_size, i18n.language)}</span>
                    {showStatus ? (
                        <ImageStatusBadge status={image.status} />
                    ) : (
                        <span className="inline-flex items-center gap-1 text-emerald-700">
                            <CheckCircle2 className="size-3.5" aria-hidden /> {t('upload.status.uploaded')}
                        </span>
                    )}
                </div>
                {image.error && <p className="text-xs text-red-600">{image.error}</p>}
                {warnings.map((w, i) => (
                    <p key={i} className="flex items-start gap-1 text-xs text-amber-800">
                        <AlertTriangle className="mt-0.5 size-3.5 shrink-0" aria-hidden />
                        {w.message ?? w}
                    </p>
                ))}
            </div>
        </div>
    );
}

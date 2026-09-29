import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { AlertTriangle, CheckCircle2, Columns2, Download, RefreshCw, ImageIcon, Loader2, RotateCcw, Trash2, XCircle } from 'lucide-react';
import { formatBytes } from '../../lib/format';
import { Badge, cx } from '../ui';

const ACTION = 'flex h-12 min-w-0 flex-col items-center justify-center gap-0.5 rounded-lg px-1 text-[11px] leading-tight font-medium text-stone-700 hover:bg-stone-100';

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
    const uploading = item.status === 'uploading';

    return (
        <div className={cx('min-w-0 rounded-2xl bg-white ring-1', item.status === 'error' ? 'ring-red-200' : 'ring-stone-200/80')}>
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
                            {item.status === 'pending'
                                ? t('upload.status.pending')
                                : ['converting', 'preparing'].includes(item.status)
                                  ? t(`upload.status.${item.status}`)
                                  : `${item.progress}%`}
                        </span>
                    )}
                </div>
                {item.error && <p className="text-xs text-red-600">{item.error}</p>}
                {item.status === 'error' && (
                    <div className="-mx-1 flex flex-wrap gap-x-0.5 pt-1">
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

const WORKING = ['analyzing', 'processing', 'finalizing'];

/** Overlay while a photo waits or is being processed. */
function StageOverlay({ status }) {
    const { t } = useTranslation();
    if (status === 'queued' || status === 'uploaded') {
        return (
            <span className="absolute top-2 left-2 rounded-full bg-white/90 px-2.5 py-1 text-xs font-medium text-stone-600 shadow-sm">
                {t('enums.image_status.queued')}
            </span>
        );
    }
    if (!WORKING.includes(status)) return null;

    return (
        <div className="absolute inset-0 grid place-items-center bg-stone-900/35 backdrop-blur-[1px]">
            <span className="inline-flex items-center gap-2 rounded-full bg-white/95 px-3 py-1.5 text-xs font-medium text-brand-800 shadow-sm">
                <Loader2 className="size-3.5 animate-spin" aria-hidden /> {t(`enums.image_status.${status}`)}
            </span>
        </div>
    );
}

/** A photo stored on the server. */
export function ServerPhotoTile({ image, onRemove, removing, showStatus = false, onOpen, watermarkSelectable = false, onToggleWatermark }) {
    const { t, i18n } = useTranslation();
    const warnings = image.warnings ?? [];
    const result = image.urls.preview ?? image.urls.optimized;
    const [original, setOriginal] = useState(false);
    const hold = useRef({ timer: null, held: false });

    // Press and hold (touch or mouse) shows the original; a short tap opens the viewer.
    const canCompare = !!(result && image.urls.thumbnail);
    const down = () => {
        if (!canCompare) return;
        hold.current.held = false;
        hold.current.timer = setTimeout(() => {
            hold.current.held = true;
            setOriginal(true);
        }, 180);
    };
    const up = () => {
        clearTimeout(hold.current.timer);
        setOriginal(false);
    };
    const click = () => {
        if (hold.current.held) {
            hold.current.held = false;
            return;
        }
        onOpen(image);
    };

    const thumb = (
        <Thumb
            src={original ? image.urls.thumbnail : (result ?? image.urls.thumbnail)}
            alt={image.original_filename}
            overlay={
                <>
                    {showStatus && <StageOverlay status={image.status} />}
                    {original && <span className="absolute top-2 left-2 rounded-full bg-black/60 px-2.5 py-1 text-xs font-medium text-white">{t('viewer.before')}</span>}
                </>
            }
        />
    );

    return (
        <div className="min-w-0 overflow-hidden rounded-2xl bg-white ring-1 ring-stone-200/80 transition hover:shadow-md">
            {onOpen ? (
                <button
                    type="button"
                    onClick={click}
                    onPointerDown={down}
                    onPointerUp={up}
                    onPointerLeave={up}
                    onPointerCancel={up}
                    onContextMenu={(e) => canCompare && e.preventDefault()}
                    className="block w-full touch-manipulation select-none [-webkit-touch-callout:none]"
                    aria-label={t('viewer.open', { name: image.original_filename })}
                >
                    {thumb}
                </button>
            ) : (
                thumb
            )}
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
                        // Waiting/working is shown on the photo itself.
                        ['completed', 'failed'].includes(image.status) && <ImageStatusBadge status={image.status} />
                    ) : (
                        <span className="inline-flex items-center gap-1 text-emerald-700">
                            <CheckCircle2 className="size-3.5" aria-hidden /> {t('upload.status.uploaded')}
                        </span>
                    )}
                </div>
                {image.status === 'completed' && image.ai_status && (
                    <p className="text-xs text-stone-400">{t(`batch.ai_status.${image.ai_status}`)}</p>
                )}
                {image.error && <p className="text-xs text-red-600">{image.error}</p>}
                {warnings.map((w, i) => (
                    <p key={i} className="flex items-start gap-1 text-xs text-amber-800">
                        <AlertTriangle className="mt-0.5 size-3.5 shrink-0" aria-hidden />
                        {w.message ?? w}
                    </p>
                ))}
                {onOpen && ['completed', 'failed'].includes(image.status) && (
                    // Icon above a short label: three equal touch targets that fit a two-column phone grid.
                    <div className="-mx-1 grid auto-cols-fr grid-flow-col gap-1 pt-1">
                        {image.urls.optimized && (
                            <button type="button" onClick={() => onOpen(image)} className={ACTION}>
                                <Columns2 className="size-4" aria-hidden /> {t('viewer.compare_short')}
                            </button>
                        )}
                        <button type="button" onClick={() => onOpen(image, 'reoptimize')} className={ACTION}>
                            <RefreshCw className="size-4" aria-hidden /> {t('viewer.reoptimize_short')}
                        </button>
                        {image.urls.download && (
                            <a href={image.urls.download} className={ACTION} aria-label={t('download.single', { name: image.output_filename })}>
                                <Download className="size-4" aria-hidden /> {t('download.short')}
                            </a>
                        )}
                    </div>
                )}
                {watermarkSelectable && image.status === 'completed' && (
                    <label className="flex cursor-pointer items-center gap-2 pt-1 text-xs text-stone-700">
                        <input type="checkbox" className="size-4 rounded border-stone-300 text-brand-600" checked={!!image.apply_watermark} onChange={(e) => onToggleWatermark(image, e.target.checked)} />
                        {t('download.logo_on_photo')}
                    </label>
                )}
            </div>
        </div>
    );
}

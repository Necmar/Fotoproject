import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { AlertTriangle, Download, Loader2, RefreshCw, X } from 'lucide-react';
import api, { errorMessage } from '../../lib/api';
import { useAuth } from '../../auth/AuthContext';
import { formatBytes } from '../../lib/format';
import { useDownload } from '../../lib/download';
import CompareSlider from './CompareSlider';
import { Choice } from './BatchSettingsForm';
import { ImageStatusBadge } from './PhotoTile';
import { Alert, Button, Toggle, cx } from '../ui';

/**
 * Full-screen viewer for one photo: before/after slider, separate
 * "Origineel" / "Geoptimaliseerd" previews, and "Opnieuw optimaliseren"
 * with new strength, background and people choices.
 */
export default function ImageViewer({ image, onClose, onReoptimized, startWithForm = false }) {
    const { t, i18n } = useTranslation();
    const { meta } = useAuth();
    const ref = useRef(null);
    const [mode, setMode] = useState(image.urls.optimized ? 'compare' : 'before');
    const [showForm, setShowForm] = useState(startWithForm || !image.urls.optimized);
    const [choices, setChoices] = useState({
        strength: image.settings?.strength ?? 'normal',
        background: image.settings?.background ?? 'keep',
        remove_people: !!image.settings?.remove_people,
    });
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const { download, busy: downloading, error: downloadError } = useDownload();

    useEffect(() => {
        ref.current?.showModal();
    }, []);

    const finished = ['completed', 'failed'].includes(image.status);
    const opts = (key, group) => (meta?.options?.[key] ?? []).map((v) => ({ value: v, label: t(`enums.${group}.${v}`) }));

    const submit = async () => {
        setBusy(true);
        setError(null);
        try {
            const { data } = await api.post(`/company/images/${image.id}/reoptimize`, choices);
            onReoptimized(data.data);
        } catch (err) {
            setError(errorMessage(err));
            setBusy(false);
        }
    };

    const views = [
        ['compare', t('viewer.compare'), !!image.urls.optimized],
        ['before', t('viewer.original'), true],
        ['after', t('viewer.optimized'), !!image.urls.optimized],
    ].filter(([, , available]) => available);

    return (
        <dialog
            ref={ref}
            onClose={onClose}
            className="m-0 h-dvh max-h-none w-full max-w-none bg-stone-50 p-0 backdrop:bg-stone-900/60 sm:m-auto sm:h-auto sm:max-h-[95dvh] sm:max-w-4xl sm:rounded-2xl"
        >
            <div className="flex h-full flex-col">
                <header className="flex items-center justify-between gap-3 border-b border-stone-200 bg-white px-4 pt-[max(0.75rem,env(safe-area-inset-top))] pb-3 sm:pt-3">
                    <div className="min-w-0">
                        <p className="truncate font-medium">
                            <span className="text-stone-400">{image.position}.</span> {image.output_filename ?? image.original_filename}
                        </p>
                        <p className="text-xs text-stone-500">
                            {image.output_width ? `${image.output_width} × ${image.output_height} px · ${formatBytes(image.output_size, i18n.language)}` : t(`batch.ai_status.${image.ai_status ?? 'skipped'}`)}
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <ImageStatusBadge status={image.status} />
                        <button type="button" onClick={() => ref.current?.close()} className="rounded-lg p-2 text-stone-500 hover:bg-stone-100" aria-label={t('common.close')}>
                            <X className="size-5" />
                        </button>
                    </div>
                </header>

                <div className="flex-1 space-y-4 overflow-y-auto overscroll-contain p-4 pb-[max(1rem,env(safe-area-inset-bottom))]">
                    {views.length > 1 && (
                        <div className="grid grid-cols-3 gap-1 rounded-xl bg-stone-200/70 p-1" role="tablist">
                            {views.map(([key, label]) => (
                                <button
                                    key={key}
                                    type="button"
                                    role="tab"
                                    aria-selected={mode === key}
                                    onClick={() => setMode(key)}
                                    className={cx('h-10 truncate rounded-lg px-1 text-xs font-medium sm:text-sm', mode === key ? 'bg-white shadow-sm' : 'text-stone-600')}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>
                    )}

                    {mode === 'compare' && image.urls.before ? (
                        <CompareSlider before={image.urls.before} after={image.urls.optimized} alt={image.original_filename} />
                    ) : (
                        <div className="grid place-items-center overflow-hidden rounded-2xl bg-stone-100">
                            {(mode === 'after' ? image.urls.optimized : image.urls.before) ? (
                                <img src={mode === 'after' ? image.urls.optimized : image.urls.before} alt={image.original_filename} className="max-h-[70dvh] w-full object-contain" />
                            ) : (
                                <p className="p-10 text-sm text-stone-500">{t('viewer.no_preview')}</p>
                            )}
                        </div>
                    )}

                    {image.error && <Alert type="error">{image.error}</Alert>}
                    {(image.warnings ?? []).map((w, i) => (
                        <p key={i} className="flex items-start gap-2 text-sm text-amber-800">
                            <AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden /> {w.message}
                        </p>
                    ))}

                    {image.urls.download && (
                        <button
                            type="button"
                            onClick={() => download(image.urls.download)}
                            disabled={!!downloading}
                            className="flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-brand-600 text-sm font-medium text-white hover:bg-brand-700 disabled:bg-brand-600/50"
                        >
                            {downloading ? <Loader2 className="size-4 animate-spin" aria-hidden /> : <Download className="size-4" aria-hidden />} {t('download.single', { name: image.output_filename })}
                        </button>
                    )}
                    <Alert type="error">{downloadError}</Alert>

                    {finished && (
                        <section className="rounded-2xl bg-white p-4 ring-1 ring-stone-200/80">
                            {!showForm ? (
                                <>
                                    <Button variant="secondary" icon={RefreshCw} className="w-full" disabled={image.reoptimize_left === 0} onClick={() => setShowForm(true)}>
                                        {t('viewer.reoptimize')}
                                    </Button>
                                    {image.reoptimize_left != null && (
                                        <p className="mt-2 text-center text-xs text-stone-500">{t('viewer.reoptimize_left', { count: image.reoptimize_left })}</p>
                                    )}
                                </>
                            ) : (
                                <div className="space-y-5">
                                    <div>
                                        <h3 className="font-semibold">{t('viewer.reoptimize')}</h3>
                                        <p className="mt-1 text-sm text-stone-500">{t('viewer.reoptimize_text')}</p>
                                    </div>
                                    <Choice label={t('fields.strength')} options={opts('strength', 'optimization_strength')} value={choices.strength} onChange={(v) => setChoices({ ...choices, strength: v })} columns="grid-cols-3" />
                                    <Choice label={t('fields.background')} options={opts('background', 'background_option')} value={choices.background} onChange={(v) => setChoices({ ...choices, background: v })} columns="grid-cols-1 sm:grid-cols-2" />
                                    <Toggle label={t('batch.settings.remove_people')} checked={choices.remove_people} onChange={(v) => setChoices({ ...choices, remove_people: v })} />
                                    <Alert type="error">{error}</Alert>
                                    <Button size="lg" icon={RefreshCw} loading={busy} className="w-full" onClick={submit}>
                                        {t('viewer.reoptimize_submit')}
                                    </Button>
                                </div>
                            )}
                        </section>
                    )}
                </div>
            </div>
        </dialog>
    );
}

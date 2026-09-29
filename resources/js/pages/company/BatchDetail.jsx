import { useEffect, useMemo, useRef, useState } from 'react';
import { Navigate, useNavigate, useParams } from 'react-router';
import { useTranslation } from 'react-i18next';
import { AlertTriangle, BellRing, CheckCircle2, Cloud, Download, Stamp, Trash2, XCircle } from 'lucide-react';
import api, { errorMessage } from '../../lib/api';
import { useFetch } from '../../lib/useFetch';
import { usePolling } from '../../lib/usePolling';
import { formatDate } from '../../lib/format';
import Stepper from '../../components/batch/Stepper';
import BatchStatusBadge, { ACTIVE_STATUSES } from '../../components/batch/BatchStatusBadge';
import { ServerPhotoTile } from '../../components/batch/PhotoTile';
import ImageViewer from '../../components/batch/ImageViewer';
import DownloadPanel from '../../components/batch/DownloadPanel';
import { useAuth } from '../../auth/AuthContext';
import { Alert, Button, Card, Modal, Spinner, cx } from '../../components/ui';

const POLL_MS = 3000;

/**
 * Steps 3 to 5: progress while processing, then results and download.
 * Processing runs on the server: the page may be closed at any time. While
 * open it polls (slower in a background tab), shows progress in the tab title
 * and can notify when the batch is done.
 */
export default function BatchDetail() {
    const { t, i18n } = useTranslation();
    const { id } = useParams();
    const navigate = useNavigate();
    const { data: batch, loading, error, reload, setBody } = useFetch(`/company/batches/${id}`);
    const { user, meta } = useAuth();
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [viewer, setViewer] = useState(null); // { id, form }
    const [deleteError, setDeleteError] = useState(null);
    const [filter, setFilter] = useState('all');
    const [watermarkOpen, setWatermarkOpen] = useState(false);

    const active = batch && ACTIVE_STATUSES.includes(batch.status);
    usePolling(reload, POLL_MS, !!active);
    useProgressTitle(batch, active, meta?.app_name);
    const notify = useDoneNotification(batch, active);

    const counts = useMemo(() => {
        const images = batch?.images ?? [];
        return {
            all: images.length,
            attention: images.filter((i) => i.status === 'completed' && (i.warnings?.length ?? 0) > 0).length,
            failed: images.filter((i) => i.status === 'failed').length,
        };
    }, [batch]);

    if (loading && !batch) return <Spinner />;
    if (error && !batch) return <Alert type="error">{error}</Alert>;
    if (batch.status === 'draft') return <Navigate to={`/batches/${id}/edit`} replace />;

    const p = batch.progress;
    const finished = !active;
    const shown = batch.images.filter((i) =>
        filter === 'attention' ? i.status === 'completed' && i.warnings?.length : filter === 'failed' ? i.status === 'failed' : true,
    );

    const remove = async () => {
        try {
            await api.delete(`/company/batches/${id}`);
            navigate('/', { replace: true });
        } catch (err) {
            setDeleteError(errorMessage(err));
        }
    };

    return (
        <div className={cx('mx-auto max-w-5xl', p.completed > 0 && 'pb-28')}>
            <div className="mb-8">
                <Stepper current={finished ? [4, 5] : 3} />
            </div>

            <div className="mb-6 flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-3">
                        <h1 className="truncate text-2xl font-semibold tracking-tight">{batch.name || t('batch.unnamed')}</h1>
                        <BatchStatusBadge status={batch.status} />
                    </div>
                    <p className="mt-1 text-sm text-stone-500">
                        {formatDate(batch.started_at ?? batch.created_at, i18n.language)} · {t('batch.expires', { date: formatDate(batch.expires_at, i18n.language, false) })}
                    </p>
                </div>
                <button type="button" onClick={() => setConfirmDelete(true)} className="grid size-10 shrink-0 place-items-center rounded-xl text-stone-400 hover:bg-red-50 hover:text-red-600" aria-label={t('common.delete')}>
                    <Trash2 className="size-5" />
                </button>
            </div>

            {active ? <ProgressCard batch={batch} notify={notify} /> : <ResultSummary progress={p} counts={counts} />}

            {finished && counts.all > 1 && (counts.attention > 0 || counts.failed > 0) && (
                <div className="mb-4 flex gap-2 overflow-x-auto pb-1" role="tablist">
                    {[
                        ['all', t('batch.filter.all'), counts.all],
                        ['attention', t('batch.filter.attention'), counts.attention],
                        ['failed', t('batch.filter.failed'), counts.failed],
                    ]
                        .filter(([key, , n]) => key === 'all' || n > 0)
                        .map(([key, label, n]) => (
                            <button
                                key={key}
                                type="button"
                                role="tab"
                                aria-selected={filter === key}
                                onClick={() => setFilter(key)}
                                className={cx(
                                    'inline-flex h-9 shrink-0 items-center gap-2 rounded-full px-4 text-sm font-medium ring-1 transition',
                                    filter === key ? 'bg-stone-900 text-white ring-stone-900' : 'bg-white text-stone-700 ring-stone-200 hover:ring-stone-300',
                                )}
                            >
                                {label}
                                <span className={cx('rounded-full px-1.5 text-xs', filter === key ? 'bg-white/20' : 'bg-stone-100')}>{n}</span>
                            </button>
                        ))}
                </div>
            )}

            {finished && counts.all > 0 && <p className="mb-3 text-xs text-stone-400">{t('batch.compare_hint')}</p>}

            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-4">
                {shown.map((image) => (
                    <ServerPhotoTile
                        key={image.id}
                        image={image}
                        showStatus
                        onOpen={(img, action) => setViewer({ id: img.id, form: action === 'reoptimize' })}
                        watermarkSelectable={batch.settings.watermark_mode === 'selected' && !!user.company?.has_logo}
                        onToggleWatermark={async (img, apply) => {
                            await api.patch(`/company/images/${img.id}/watermark`, { apply }).catch(() => null);
                            reload();
                        }}
                    />
                ))}
            </div>

            {p.completed > 0 && (
                <div className="fixed inset-x-0 bottom-0 z-20 border-t border-stone-200/70 bg-white/95 pb-[max(0.75rem,env(safe-area-inset-bottom))] backdrop-blur">
                    <div className="mx-auto flex max-w-5xl items-center gap-3 px-4 pt-3 sm:px-6">
                        <div className="hidden min-w-0 flex-1 sm:block">
                            <p className="font-medium">{t('download.title')}</p>
                            <p className="truncate text-sm text-stone-500">
                                {t('download.text', { count: p.completed })}
                                {active && ` · ${t('download.more_coming')}`}
                            </p>
                        </div>
                        <Button variant="secondary" icon={Stamp} onClick={() => setWatermarkOpen(true)} className="shrink-0" aria-label={t('settings.watermark')}>
                            <span className="hidden sm:inline">{t('settings.watermark')}</span>
                        </Button>
                        <Button size="lg" icon={Download} onClick={() => (window.location.href = batch.download_url)} disabled={!batch.download_url} className="flex-1 sm:flex-none">
                            {t('download.zip_count', { count: p.completed })}
                        </Button>
                    </div>
                </div>
            )}

            {viewer && batch.images.some((i) => i.id === viewer.id) && (
                <ImageViewer
                    key={viewer.id}
                    image={batch.images.find((i) => i.id === viewer.id)}
                    startWithForm={viewer.form}
                    onClose={() => setViewer(null)}
                    onReoptimized={() => {
                        setViewer(null);
                        reload(); // batch is active again, so polling resumes
                    }}
                />
            )}

            <Modal open={watermarkOpen} onClose={() => setWatermarkOpen(false)} title={t('settings.watermark')} footer={<Button onClick={() => setWatermarkOpen(false)}>{t('common.done')}</Button>}>
                <DownloadPanel batch={batch} onBatchChange={(data) => setBody({ data })} />
            </Modal>

            <Modal
                open={confirmDelete}
                onClose={() => setConfirmDelete(false)}
                title={t('batch.delete_title')}
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setConfirmDelete(false)}>
                            {t('common.cancel')}
                        </Button>
                        <Button variant="danger" onClick={remove}>
                            {t('common.delete')}
                        </Button>
                    </>
                }
            >
                <p className="text-sm text-stone-600">{t('batch.delete_text')}</p>
                <Alert type="error">{deleteError}</Alert>
            </Modal>
        </div>
    );
}

/** Step 3: live progress, an estimate of the time left, and the reassurance that closing the page is fine. */
function ProgressCard({ batch, notify }) {
    const { t } = useTranslation();
    const p = batch.progress;
    const done = p.completed + p.failed;
    const eta = useEta(batch.started_at, done, p.total);
    const busy = batch.images.filter((i) => ['analyzing', 'processing', 'finalizing'].includes(i.status)).length;

    return (
        <Card className="mb-8 overflow-hidden">
            <div className="flex items-end justify-between gap-4">
                <div>
                    <p className="text-sm font-medium text-brand-700">{batch.status === 'queued' && busy === 0 ? t('batch.progress.starting') : t('batch.progress.working', { count: busy || 1 })}</p>
                    <p className="mt-1 text-3xl font-semibold tracking-tight">{t('batch.progress.done_of', { done: p.completed, total: p.total })}</p>
                </div>
                <p className="text-3xl font-semibold text-stone-300 tabular-nums">{p.percent}%</p>
            </div>

            <div className="mt-4 h-3 overflow-hidden rounded-full bg-stone-100" role="progressbar" aria-valuenow={p.percent} aria-valuemin={0} aria-valuemax={100}>
                <div className="progress-stripes h-full rounded-full bg-brand-600 transition-all duration-700" style={{ width: `${Math.max(p.percent, 3)}%` }} />
            </div>

            <div className="mt-3 flex flex-wrap items-center justify-between gap-2 text-sm text-stone-500">
                <span>{eta === null ? t('batch.progress.eta_unknown') : eta < 60 ? t('batch.progress.eta_soon') : t('batch.progress.eta_minutes', { count: Math.ceil(eta / 60) })}</span>
                {p.failed > 0 && <span className="text-red-600">{t('batch.progress.failed_count', { count: p.failed })}</span>}
            </div>

            <div className="mt-5 flex flex-wrap items-center gap-3 rounded-xl bg-stone-50 p-4">
                <div className="flex min-w-60 flex-1 items-start gap-3">
                    <Cloud className="mt-0.5 size-5 shrink-0 text-brand-600" aria-hidden />
                    <p className="text-sm text-stone-600">{t('batch.progress.background')}</p>
                </div>
                {notify.available && !notify.enabled && (
                    <Button variant="secondary" size="sm" icon={BellRing} onClick={notify.enable} className="shrink-0">
                        {t('batch.progress.notify')}
                    </Button>
                )}
                {notify.enabled && (
                    <span className="inline-flex shrink-0 items-center gap-1.5 text-sm text-emerald-700">
                        <BellRing className="size-4" aria-hidden /> {t('batch.progress.notify_on')}
                    </span>
                )}
            </div>
        </Card>
    );
}

/** Steps 4 and 5: a short verdict above the results. */
function ResultSummary({ progress: p, counts }) {
    const { t } = useTranslation();
    const allGood = p.failed === 0 && counts.attention === 0;

    return (
        <div className={cx('mb-6 flex items-start gap-3 rounded-2xl p-4 ring-1', allGood ? 'bg-emerald-50 ring-emerald-200' : 'bg-white ring-stone-200/80')}>
            {allGood ? <CheckCircle2 className="mt-0.5 size-5 shrink-0 text-emerald-600" aria-hidden /> : p.failed > 0 ? <XCircle className="mt-0.5 size-5 shrink-0 text-red-500" aria-hidden /> : <AlertTriangle className="mt-0.5 size-5 shrink-0 text-amber-500" aria-hidden />}
            <div className="text-sm">
                <p className="font-medium text-stone-900">{t('batch.result.title', { count: p.completed })}</p>
                <p className="mt-0.5 text-stone-600">
                    {allGood
                        ? t('batch.result.all_good')
                        : [p.failed > 0 && t('batch.result.failed', { count: p.failed }), counts.attention > 0 && t('batch.result.attention', { count: counts.attention })].filter(Boolean).join(' · ')}
                </p>
            </div>
        </div>
    );
}

/** Seconds left, estimated from the pace so far (parallel processing included); null until the first photo is done. */
function useEta(startedAt, done, total) {
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => {
        const timer = setInterval(() => setNow(Date.now()), 5000);
        return () => clearInterval(timer);
    }, []);

    if (!startedAt || done === 0 || done >= total) return null;
    const elapsed = (now - new Date(startedAt).getTime()) / 1000;
    return Math.max(0, Math.round((elapsed / done) * (total - done)));
}

/** "(7/20) Bora Foto" in the tab while processing, so progress shows in the tab bar. */
function useProgressTitle(batch, active, appName = 'Bora Foto') {
    useEffect(() => {
        const original = document.title;
        if (batch && active) {
            document.title = `(${batch.progress.completed}/${batch.progress.total}) ${appName}`;
        }
        return () => {
            document.title = original;
        };
    }, [batch, active, appName]);
}

/** Optional browser notification when the batch finishes while this page is open in the background. */
function useDoneNotification(batch, active) {
    const { t } = useTranslation();
    const available = typeof window !== 'undefined' && 'Notification' in window;
    const [enabled, setEnabled] = useState(() => available && Notification.permission === 'granted');
    const wasActive = useRef(active);

    useEffect(() => {
        if (wasActive.current && !active && batch && enabled && document.visibilityState !== 'visible') {
            try {
                new Notification(t('batch.progress.notify_title'), { body: t('batch.progress.notify_body', { count: batch.progress.completed, name: batch.name || t('batch.unnamed') }), tag: `batch-${batch.id}` });
            } catch {
                // Some browsers (iOS) only allow notifications from an installed app.
            }
        }
        wasActive.current = active;
    }, [active, batch, enabled, t]);

    const enable = async () => {
        try {
            setEnabled((await Notification.requestPermission()) === 'granted');
        } catch {
            setEnabled(false);
        }
    };

    return { available: available && Notification.permission !== 'denied', enabled, enable };
}


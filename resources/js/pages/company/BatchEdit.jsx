import { useCallback, useEffect, useMemo, useState } from 'react';
import { Navigate, useNavigate, useParams } from 'react-router';
import { useTranslation } from 'react-i18next';
import { ArrowLeft, ArrowRight, Clock, ImagePlus, ShieldCheck, Sparkles, Trash2 } from 'lucide-react';
import api, { errorMessage, fieldErrors } from '../../lib/api';
import { useFetch } from '../../lib/useFetch';
import { useWakeLock } from '../../lib/useWakeLock';
import { useUploadQueue } from '../../lib/useUploadQueue';
import { formatBytes } from '../../lib/format';
import { useAuth } from '../../auth/AuthContext';
import Stepper from '../../components/batch/Stepper';
import PhotoPicker from '../../components/batch/PhotoPicker';
import { LocalPhotoTile, ServerPhotoTile } from '../../components/batch/PhotoTile';
import BatchSettingsForm from '../../components/batch/BatchSettingsForm';
import { Alert, Button, Modal, Spinner } from '../../components/ui';

/**
 * Steps 1 to 3 of a new batch: add photos, choose settings, start.
 * The batch is a server-side draft, so the user can leave and come back.
 */
export default function BatchEdit() {
    const { id } = useParams();
    const { data: batch, loading, error } = useFetch(`/company/batches/${id}`);

    if (loading && !batch) return <Spinner />;
    if (error) return <Alert type="error">{error}</Alert>;
    if (batch.status !== 'draft') return <Navigate to={`/batches/${id}`} replace />;

    return <Wizard initial={batch} />;
}

function Wizard({ initial }) {
    const { t, i18n } = useTranslation();
    const { meta, user } = useAuth();
    const navigate = useNavigate();
    const [step, setStep] = useState(1);
    const [images, setImages] = useState(initial.images);
    const [form, setForm] = useState({ name: initial.name ?? '', settings: initial.settings, fallbackBase: initial.filename_base });
    const [removing, setRemoving] = useState(null);
    const [notice, setNotice] = useState(null);
    const [errors, setErrors] = useState({});
    const [starting, setStarting] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState(false);

    const maxFiles = meta?.max_images_per_batch ?? 30;
    const maxMb = meta?.max_upload_mb ?? 25;

    const onUploaded = useCallback((image) => setImages((list) => [...list, image].sort((a, b) => a.position - b.position)), []);
    const queue = useUploadQueue({ batchId: initial.id, maxFiles, maxMb, existingCount: images.length, onUploaded });

    const inQueue = queue.items.filter((i) => i.status !== 'error').length;
    const remaining = Math.max(0, maxFiles - images.length - inQueue);
    const totalBytes = useMemo(() => images.reduce((sum, i) => sum + (i.original_size ?? 0), 0), [images]);

    // Keep the screen on and warn before leaving while uploads are still running.
    useWakeLock(queue.busy);
    useEffect(() => {
        if (!queue.busy) return undefined;
        const handler = (e) => {
            e.preventDefault();
            e.returnValue = '';
        };
        window.addEventListener('beforeunload', handler);
        return () => window.removeEventListener('beforeunload', handler);
    }, [queue.busy]);

    // Step 1: drop photos anywhere on the page, or paste them (e.g. a screenshot) with Ctrl/Cmd+V.
    const [dragging, setDragging] = useState(false);
    useEffect(() => {
        if (step !== 1) return undefined;
        let depth = 0;
        const hasFiles = (e) => Array.from(e.dataTransfer?.types ?? []).includes('Files');
        const enter = (e) => {
            if (!hasFiles(e)) return;
            depth += 1;
            setDragging(true);
        };
        const leave = () => {
            depth = Math.max(0, depth - 1);
            if (depth === 0) setDragging(false);
        };
        const over = (e) => hasFiles(e) && e.preventDefault();
        const drop = (e) => {
            if (!hasFiles(e)) return;
            e.preventDefault();
            depth = 0;
            setDragging(false);
            queue.add(e.dataTransfer.files);
        };
        const paste = (e) => {
            const files = Array.from(e.clipboardData?.files ?? []).filter((f) => f.type.startsWith('image/'));
            if (files.length) queue.add(files);
        };
        window.addEventListener('dragenter', enter);
        window.addEventListener('dragleave', leave);
        window.addEventListener('dragover', over);
        window.addEventListener('drop', drop);
        document.addEventListener('paste', paste);
        return () => {
            window.removeEventListener('dragenter', enter);
            window.removeEventListener('dragleave', leave);
            window.removeEventListener('dragover', over);
            window.removeEventListener('drop', drop);
            document.removeEventListener('paste', paste);
        };
    }, [step, queue.add]);

    const removeImage = async (image) => {
        setRemoving(image.id);
        try {
            await api.delete(`/company/batches/${initial.id}/images/${image.id}`);
            setImages((list) => list.filter((i) => i.id !== image.id));
        } catch (err) {
            setNotice({ type: 'error', text: errorMessage(err) });
        } finally {
            setRemoving(null);
        }
    };

    const start = async () => {
        setStarting(true);
        setErrors({});
        setNotice(null);
        try {
            await api.patch(`/company/batches/${initial.id}`, { name: form.name || null, ...form.settings });
            await api.post(`/company/batches/${initial.id}/start`);
            navigate(`/batches/${initial.id}`, { replace: true });
        } catch (err) {
            setErrors(fieldErrors(err));
            setNotice({ type: 'error', text: errorMessage(err) });
            setStarting(false);
        }
    };

    const discard = async () => {
        await api.delete(`/company/batches/${initial.id}`).catch(() => null);
        navigate('/', { replace: true });
    };

    return (
        <div className="mx-auto max-w-5xl">
            <div className="mb-8">
                <Stepper current={step === 1 ? 1 : 2} onStep={(s) => s === 1 && setStep(1)} />
            </div>

            {dragging && (
                <div className="pointer-events-none fixed inset-0 z-50 grid place-items-center bg-brand-900/30 p-6 backdrop-blur-sm">
                    <div className="rounded-3xl border-2 border-dashed border-white bg-white/90 px-10 py-12 text-center shadow-xl">
                        <ImagePlus className="mx-auto size-10 text-brand-600" aria-hidden />
                        <p className="mt-3 text-lg font-semibold">{t('upload.drop_here')}</p>
                    </div>
                </div>
            )}

            {notice && (
                <Alert type={notice.type} onClose={() => setNotice(null)} className="mb-6">
                    {notice.text}
                </Alert>
            )}

            {step === 1 && (
                <section aria-labelledby="step-photos">
                    <div className="mb-6 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <h1 id="step-photos" className="text-2xl font-semibold tracking-tight">
                                {t('batch.steps.photos')}
                            </h1>
                            <p className="mt-1 text-stone-500">{t('batch.photos_intro', { max: maxFiles })}</p>
                            <p className="mt-1 hidden text-sm text-stone-400 sm:block">{t('upload.drop_paste_hint')}</p>
                        </div>
                        {images.length > 0 && (
                            <p className="text-sm text-stone-500">
                                {t('batch.photo_count', { count: images.length, max: maxFiles })} · {formatBytes(totalBytes, i18n.language)}
                            </p>
                        )}
                    </div>

                    <PhotoPicker onFiles={queue.add} disabled={remaining === 0} remaining={remaining} compact={images.length + queue.items.length > 0} />

                    {queue.notice && (
                        <Alert type="warning" onClose={queue.clearNotice} className="mt-4">
                            {queue.notice}
                        </Alert>
                    )}

                    {(images.length > 0 || queue.items.length > 0) && (
                        <div className="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-4">
                            {images.map((image) => (
                                <ServerPhotoTile key={image.id} image={image} onRemove={removeImage} removing={removing === image.id} />
                            ))}
                            {queue.items.map((item) => (
                                <LocalPhotoTile key={item.id} item={item} onRetry={queue.retry} onRemove={queue.remove} />
                            ))}
                        </div>
                    )}

                    <StickyActions>
                        <Button variant="ghost" icon={Trash2} onClick={() => setConfirmDelete(true)}>
                            {t('batch.discard')}
                        </Button>
                        <Button size="lg" onClick={() => setStep(2)} disabled={!images.length || queue.busy} className="sm:min-w-56">
                            {queue.busy ? t('batch.wait_uploads') : t('batch.next_settings')}
                            {!queue.busy && <ArrowRight className="size-4" aria-hidden />}
                        </Button>
                    </StickyActions>
                </section>
            )}

            {step === 2 && (
                <section aria-labelledby="step-settings">
                    <h1 id="step-settings" className="mb-1 text-2xl font-semibold tracking-tight">
                        {t('batch.steps.settings')}
                    </h1>
                    <p className="mb-6 text-stone-500">{t('batch.settings_intro', { count: images.length })}</p>

                    <div className="lg:grid lg:grid-cols-[1fr_20rem] lg:items-start lg:gap-6">
                        <BatchSettingsForm value={form} onChange={setForm} hasLogo={!!user.company?.has_logo} errors={errors} />

                        {/* Desktop: summary with the start button always in view. */}
                        <aside className="hidden lg:sticky lg:top-24 lg:block">
                            <div className="rounded-2xl bg-white p-5 ring-1 ring-stone-200/80">
                                <div className="grid grid-cols-3 gap-1.5 overflow-hidden rounded-xl">
                                    {images.slice(0, 6).map((image) => (
                                        <img key={image.id} src={image.urls.thumbnail} alt="" className="aspect-square size-full rounded-md object-cover" />
                                    ))}
                                </div>
                                <p className="mt-4 font-semibold">{t('batch.photo_count_short', { count: images.length })}</p>
                                <ul className="mt-3 space-y-2 text-sm text-stone-600">
                                    <li className="flex gap-2">
                                        <Sparkles className="mt-0.5 size-4 shrink-0 text-brand-600" aria-hidden /> {t('batch.summary.per_photo')}
                                    </li>
                                    <li className="flex gap-2">
                                        <ShieldCheck className="mt-0.5 size-4 shrink-0 text-brand-600" aria-hidden /> {t('batch.summary.integrity')}
                                    </li>
                                    <li className="flex gap-2">
                                        <Clock className="mt-0.5 size-4 shrink-0 text-brand-600" aria-hidden /> {t('batch.summary.background')}
                                    </li>
                                </ul>
                                <Button size="lg" icon={Sparkles} loading={starting} onClick={start} className="mt-5 w-full">
                                    {t('batch.start', { count: images.length })}
                                </Button>
                                <Button variant="ghost" icon={ArrowLeft} onClick={() => setStep(1)} className="mt-2 w-full">
                                    {t('batch.back_photos')}
                                </Button>
                            </div>
                        </aside>
                    </div>

                    <StickyActions className="lg:hidden">
                        <Button variant="ghost" icon={ArrowLeft} onClick={() => setStep(1)}>
                            {t('batch.back_photos')}
                        </Button>
                        <Button size="lg" icon={Sparkles} loading={starting} onClick={start} className="sm:min-w-56">
                            {t('batch.start', { count: images.length })}
                        </Button>
                    </StickyActions>
                </section>
            )}

            <Modal
                open={confirmDelete}
                onClose={() => setConfirmDelete(false)}
                title={t('batch.discard')}
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setConfirmDelete(false)}>
                            {t('common.cancel')}
                        </Button>
                        <Button variant="danger" onClick={discard}>
                            {t('batch.discard_confirm')}
                        </Button>
                    </>
                }
            >
                <p className="text-sm text-stone-600">{t('batch.discard_text')}</p>
            </Modal>
        </div>
    );
}

/** Primary actions stay reachable at the bottom of the screen on phones. */
function StickyActions({ children, className }) {
    return (
        <div className={`sticky bottom-0 z-10 -mx-4 mt-8 border-t border-stone-200/70 bg-stone-50/95 px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] backdrop-blur sm:static sm:mx-0 sm:border-0 sm:bg-transparent sm:px-0 sm:pt-0 sm:pb-0 ${className ?? ''}`}>
            <div className="flex flex-col-reverse gap-2 sm:flex-row sm:items-center sm:justify-between">{children}</div>
        </div>
    );
}

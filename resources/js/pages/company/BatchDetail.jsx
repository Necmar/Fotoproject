import { useState } from 'react';
import { Navigate, useNavigate, useParams } from 'react-router';
import { useTranslation } from 'react-i18next';
import { Clock, Trash2 } from 'lucide-react';
import api, { errorMessage } from '../../lib/api';
import { useFetch } from '../../lib/useFetch';
import { usePolling } from '../../lib/usePolling';
import { formatDate } from '../../lib/format';
import Stepper from '../../components/batch/Stepper';
import BatchStatusBadge, { ACTIVE_STATUSES } from '../../components/batch/BatchStatusBadge';
import { ServerPhotoTile } from '../../components/batch/PhotoTile';
import ImageViewer from '../../components/batch/ImageViewer';
import { Alert, Button, Card, Modal, Spinner } from '../../components/ui';

const POLL_MS = 3000;

/**
 * Steps 3 to 5: progress while processing, then results. Polls the batch
 * every few seconds while it is active; finished photos show up right away.
 */
export default function BatchDetail() {
    const { t, i18n } = useTranslation();
    const { id } = useParams();
    const navigate = useNavigate();
    const { data: batch, loading, error, reload } = useFetch(`/company/batches/${id}`);
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [viewer, setViewer] = useState(null); // { id, form }
    const [deleteError, setDeleteError] = useState(null);

    const active = batch && ACTIVE_STATUSES.includes(batch.status);
    usePolling(reload, POLL_MS, !!active);

    if (loading && !batch) return <Spinner />;
    if (error && !batch) return <Alert type="error">{error}</Alert>;
    if (batch.status === 'draft') return <Navigate to={`/batches/${id}/edit`} replace />;

    const p = batch.progress;
    const finished = !active;
    const current = p.current_position ? batch.images.find((i) => i.position === p.current_position) : null;

    const remove = async () => {
        try {
            await api.delete(`/company/batches/${id}`);
            navigate('/', { replace: true });
        } catch (err) {
            setDeleteError(errorMessage(err));
        }
    };

    return (
        <div className="mx-auto max-w-5xl">
            <div className="mb-8">
                <Stepper current={finished ? 4 : 3} />
            </div>

            <div className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <div className="flex flex-wrap items-center gap-3">
                        <h1 className="text-2xl font-semibold tracking-tight">{batch.name || t('batch.unnamed')}</h1>
                        <BatchStatusBadge status={batch.status} />
                    </div>
                    <p className="mt-1 text-sm text-stone-500">
                        {formatDate(batch.started_at ?? batch.created_at, i18n.language)} · {t('batch.expires', { date: formatDate(batch.expires_at, i18n.language, false) })}
                    </p>
                </div>
                <Button variant="ghost" icon={Trash2} onClick={() => setConfirmDelete(true)}>
                    {t('common.delete')}
                </Button>
            </div>

            <Card className="mb-8">
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                    <p className="text-lg font-semibold">{t('batch.progress.done_of', { done: p.completed, total: p.total })}</p>
                    <p className="text-sm text-stone-500">{p.percent}%</p>
                </div>
                <div className="mt-3 h-2.5 overflow-hidden rounded-full bg-stone-100" role="progressbar" aria-valuenow={p.percent} aria-valuemin={0} aria-valuemax={100}>
                    <div className="h-full rounded-full bg-brand-600 transition-all duration-500" style={{ width: `${p.percent}%` }} />
                </div>
                <dl className="mt-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                    <div>
                        <dt className="text-stone-500">{t('batch.progress.succeeded')}</dt>
                        <dd className="font-medium">{p.completed}</dd>
                    </div>
                    <div>
                        <dt className="text-stone-500">{t('batch.progress.failed')}</dt>
                        <dd className={p.failed ? 'font-medium text-red-600' : 'font-medium'}>{p.failed}</dd>
                    </div>
                    <div className="col-span-2">
                        <dt className="text-stone-500">{t('batch.progress.current')}</dt>
                        <dd className="truncate font-medium">{current ? `${current.position}. ${current.original_filename}` : '-'}</dd>
                    </div>
                </dl>
                {batch.status === 'queued' && (
                    <p className="mt-4 flex items-center gap-2 text-sm text-stone-500">
                        <Clock className="size-4" aria-hidden /> {t('batch.progress.waiting')}
                    </p>
                )}
                {active && <p className="mt-2 text-xs text-stone-400">{t('batch.progress.stay')}</p>}
            </Card>

            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-4">
                {batch.images.map((image) => (
                    <ServerPhotoTile key={image.id} image={image} showStatus onOpen={(img, action) => setViewer({ id: img.id, form: action === 'reoptimize' })} />
                ))}
            </div>

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

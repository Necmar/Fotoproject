import { useState } from 'react';
import { Link } from 'react-router';
import { useTranslation } from 'react-i18next';
import { Download, ImageIcon, Images, ImagePlus, Trash2 } from 'lucide-react';
import api, { errorMessage } from '../../lib/api';
import { useAuth } from '../../auth/AuthContext';
import { useFetch } from '../../lib/useFetch';
import { usePolling } from '../../lib/usePolling';
import { batchTiming, batchTitle, formatDate } from '../../lib/format';
import { useDownload } from '../../lib/download';
import BatchStatusBadge, { ACTIVE_STATUSES } from '../../components/batch/BatchStatusBadge';
import { Alert, Button, Card, EmptyState, Modal, PageHeader, Pagination, Spinner } from '../../components/ui';

/** "Mijn verwerkingen": batches of the last retention period (default 7 days), as cards (mobile friendly). */
export default function History() {
    const { t, i18n } = useTranslation();
    const { meta } = useAuth();
    const [page, setPage] = useState(1);
    const { data, meta: pageMeta, loading, error, reload, refetch } = useFetch('/company/batches', { per_page: 12, page });
    const zip = useDownload();
    const [target, setTarget] = useState(null);
    const [notice, setNotice] = useState(null);
    const [busy, setBusy] = useState(false);
    const l = i18n.language;

    usePolling(refetch, 5000, !!data?.some((b) => ACTIVE_STATUSES.includes(b.status)));

    const remove = async () => {
        setBusy(true);
        try {
            const res = await api.delete(`/company/batches/${target.id}`);
            setNotice({ type: 'success', text: res.data.message });
            // The last card of a later page is gone: show the previous page instead of an empty one.
            if (data?.length === 1 && page > 1) setPage(page - 1);
            else reload();
        } catch (err) {
            setNotice({ type: 'error', text: errorMessage(err) });
        } finally {
            setBusy(false);
            setTarget(null);
        }
    };

    const summary = (s) =>
        [t(`enums.output_format.${s.output_format}`), `${s.resolution} px`, t(`enums.aspect_ratio.${s.aspect_ratio}`), t(`enums.optimization_strength.${s.strength}`)].join(' · ');

    return (
        <div>
            <PageHeader
                title={t('history.title')}
                description={t('history.subtitle', { days: meta?.retention_days ?? 7 })}
                actions={
                    <Button icon={ImagePlus} to="/batches/new">
                        {t('dashboard.optimize')}
                    </Button>
                }
            />

            {notice && (
                <Alert type={notice.type} onClose={() => setNotice(null)} className="mb-5">
                    {notice.text}
                </Alert>
            )}
            <Alert type="error">{error}</Alert>
            {zip.error && (
                <Alert type="error" onClose={zip.clearError} className="mb-5">
                    {zip.error}
                </Alert>
            )}

            {loading && !data ? (
                <Spinner />
            ) : !data?.length ? (
                <Card>
                    <EmptyState icon={Images} title={t('dashboard.no_batches')} description={t('history.empty')} />
                </Card>
            ) : (
                <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {data.map((b) => (
                        <li key={b.id}>
                            <Card padded={false} className="flex h-full flex-col overflow-hidden">
                                <Link to={b.status === 'draft' ? `/batches/${b.id}/edit` : `/batches/${b.id}`} className="block aspect-[4/3] bg-stone-100">
                                    {b.cover_url ? (
                                        <img src={b.cover_url} alt="" loading="lazy" className="size-full object-cover" />
                                    ) : (
                                        <div className="grid size-full place-items-center text-stone-400">
                                            <ImageIcon className="size-7" aria-hidden />
                                        </div>
                                    )}
                                </Link>
                                <div className="flex flex-1 flex-col gap-2 p-4">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="truncate font-medium">{batchTitle(b, t, l)}</span>
                                        <BatchStatusBadge status={b.status} />
                                    </div>
                                    <p className="text-sm text-stone-500">
                                        {t('batch.photo_count_short', { count: b.images_count })} · {batchTiming(b, t, l)}
                                    </p>
                                    <p className="text-xs text-stone-400">{summary(b.settings)}</p>
                                    {b.expires_at && <p className="text-xs text-stone-400">{t('batch.expires', { date: formatDate(b.expires_at, l, false) })}</p>}
                                    <div className="mt-auto flex flex-wrap gap-2 pt-2">
                                        <Button size="sm" variant="secondary" to={b.status === 'draft' ? `/batches/${b.id}/edit` : `/batches/${b.id}`}>
                                            {t('history.open')}
                                        </Button>
                                        {b.download_url && (
                                            <Button size="sm" variant="secondary" icon={Download} loading={zip.busy === b.download_url} disabled={!!zip.busy} onClick={() => zip.download(b.download_url)}>
                                                ZIP
                                            </Button>
                                        )}
                                        <Button size="sm" variant="danger-ghost" icon={Trash2} onClick={() => setTarget(b)} aria-label={t('common.delete')}>
                                            {t('common.delete')}
                                        </Button>
                                    </div>
                                </div>
                            </Card>
                        </li>
                    ))}
                </ul>
            )}

            <Pagination
                meta={pageMeta}
                onPage={setPage}
                labels={{ summary: t('common.page_of', { page: pageMeta?.current_page, total: pageMeta?.last_page }), previous: t('common.previous'), next: t('common.next') }}
            />

            <Modal
                open={!!target}
                onClose={() => setTarget(null)}
                title={t('batch.delete_title')}
                footer={
                    <>
                        <Button variant="ghost" disabled={busy} onClick={() => setTarget(null)}>
                            {t('common.cancel')}
                        </Button>
                        <Button variant="danger" loading={busy} onClick={remove}>
                            {t('common.delete')}
                        </Button>
                    </>
                }
            >
                <p className="text-sm text-stone-600">{t('batch.delete_text')}</p>
            </Modal>
        </div>
    );
}

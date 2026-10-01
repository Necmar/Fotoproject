import { useState } from 'react';
import { Link } from 'react-router';
import { useTranslation } from 'react-i18next';
import { Images, Trash2 } from 'lucide-react';
import api, { errorMessage } from '../../lib/api';
import { useFetch } from '../../lib/useFetch';
import { formatBytes, formatDate } from '../../lib/format';
import { Alert, Badge, Button, Card, EmptyState, Modal, PageHeader, Pagination, Spinner } from '../../components/ui';

export default function AdminBatches() {
    const { t, i18n } = useTranslation();
    const [query, setQuery] = useState({ page: 1 });
    const { data, meta, loading, error, reload } = useFetch('/admin/batches', query);
    const [target, setTarget] = useState(null);
    const [notice, setNotice] = useState(null);
    const [busy, setBusy] = useState(false);
    const l = i18n.language;

    const remove = async () => {
        setBusy(true);
        try {
            const res = await api.delete(`/admin/batches/${target.id}`);
            setNotice({ type: 'success', text: res.data.message });
            reload();
        } catch (err) {
            setNotice({ type: 'error', text: errorMessage(err) });
        } finally {
            setBusy(false);
            setTarget(null);
        }
    };

    return (
        <div>
            <PageHeader title={t('admin.batches.title')} description={t('admin.batches.subtitle')} />
            {notice && (
                <Alert type={notice.type} onClose={() => setNotice(null)} className="mb-5">
                    {notice.text}
                </Alert>
            )}
            <Alert type="error">{error}</Alert>
            <Card padded={false}>
                {loading && !data ? (
                    <div className="p-10">
                        <Spinner />
                    </div>
                ) : data?.length === 0 ? (
                    <EmptyState icon={Images} title={t('admin.batches.empty')} description={t('admin.batches.empty_text')} />
                ) : (
                    <ul className="divide-y divide-stone-100">
                        {data?.map((b) => (
                            <li key={b.id} className="flex flex-col gap-2 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                                <div>
                                    <div className="flex items-center gap-2">
                                        <span className="font-medium">{b.name || t('admin.batches.unnamed')}</span>
                                        <Badge>{t(`enums.batch_status.${b.status}`)}</Badge>
                                    </div>
                                    <p className="text-sm text-stone-500">
                                        <Link to={`/admin/companies/${b.company?.id}`} className="hover:underline">
                                            {b.company?.name}
                                        </Link>{' '}
                                        · {formatDate(b.created_at, l)} · {t('admin.batches.images', { done: b.completed_count, total: b.images_count })} · {formatBytes(b.storage_bytes, l)}
                                    </p>
                                </div>
                                <Button variant="danger-ghost" size="sm" icon={Trash2} onClick={() => setTarget(b)}>
                                    {t('common.delete')}
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>
            <Pagination
                meta={meta}
                onPage={(page) => setQuery({ page })}
                labels={{
                    summary: t('common.page_of', { page: meta?.current_page, total: meta?.last_page }),
                    previous: t('common.previous'),
                    next: t('common.next'),
                }}
            />
            <Modal
                open={!!target}
                onClose={() => setTarget(null)}
                title={t('admin.batches.delete_title')}
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
                {target && (
                    <p className="text-sm font-medium text-stone-800">
                        {target.name || t('admin.batches.unnamed')} · {target.company?.name ?? t('admin.activity.deleted_company')}
                    </p>
                )}
                <p className="text-sm text-stone-600">{t('admin.batches.delete_text')}</p>
                {target && ['uploading', 'queued', 'processing'].includes(target.status) && <Alert type="warning">{t('admin.batches.delete_active')}</Alert>}
            </Modal>
        </div>
    );
}

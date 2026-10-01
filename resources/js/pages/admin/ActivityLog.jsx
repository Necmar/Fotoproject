import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ScrollText } from 'lucide-react';
import { useFetch } from '../../lib/useFetch';
import { formatDate } from '../../lib/format';
import { Alert, Card, EmptyState, PageHeader, Pagination, Spinner } from '../../components/ui';

export default function ActivityLog() {
    const { t, i18n } = useTranslation();
    const [query, setQuery] = useState({ page: 1 });
    const { data, meta, loading, error } = useFetch('/admin/activity', query);

    return (
        <div>
            <PageHeader title={t('admin.activity.title')} description={t('admin.activity.subtitle')} />
            <Alert type="error">{error}</Alert>
            <Card padded={false}>
                {loading && !data ? (
                    <div className="p-10">
                        <Spinner />
                    </div>
                ) : data?.length === 0 ? (
                    <EmptyState icon={ScrollText} title={t('admin.activity.empty')} />
                ) : (
                    <ul className="divide-y divide-stone-100">
                        {data?.map((log) => (
                            <li key={log.id} className="flex flex-col gap-1 px-5 py-3.5 sm:flex-row sm:items-center sm:gap-6 sm:px-6">
                                <span className="w-40 shrink-0 text-xs text-stone-400">{formatDate(log.created_at, i18n.language)}</span>
                                <span className="flex-1 text-sm">
                                    <span className="font-medium text-stone-800">{t(`enums.activity_action.${log.action}`, log.action_label)}</span>
                                    {log.company ? (
                                        <span className="text-stone-500"> · {log.company.name}</span>
                                    ) : (
                                        log.properties?.company_name && (
                                            <span className="text-stone-500">
                                                {' '}
                                                · {log.properties.company_name} ({t('admin.activity.deleted_company')})
                                            </span>
                                        )
                                    )}
                                    {log.user && <span className="text-stone-500"> · {log.user.email}</span>}
                                    {!log.user && log.properties?.email && <span className="text-stone-500"> · {log.properties.email}</span>}
                                </span>
                                <span className="text-xs text-stone-400">{log.ip_address}</span>
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
        </div>
    );
}

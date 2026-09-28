import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { useFetch } from '../../lib/useFetch';
import { formatBytes, formatDate, formatNumber, formatUsd } from '../../lib/format';
import { Alert, Card, PageHeader, Spinner, StatCard } from '../../components/ui';

export default function AdminDashboard() {
    const { t, i18n } = useTranslation();
    const { data, loading, error } = useFetch('/admin/dashboard');
    const l = i18n.language;

    if (loading) return <Spinner />;
    if (error) return <Alert type="error">{error}</Alert>;

    const s = data.totals;

    return (
        <div>
            <PageHeader title={t('admin.dashboard.title')} description={t('admin.dashboard.subtitle')} />

            <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatCard label={t('admin.stats.companies')} value={formatNumber(s.companies, l)} hint={t('admin.stats.blocked', { count: s.companies_blocked })} />
                <StatCard label={t('admin.stats.active_batches')} value={formatNumber(s.batches_active, l)} hint={t('admin.stats.batches_current', { count: s.batches_current })} />
                <StatCard label={t('admin.stats.images_processed')} value={formatNumber(s.images_processed_total, l)} hint={t('admin.stats.failed_current', { count: s.images_failed_current })} />
                <StatCard label={t('admin.stats.storage')} value={formatBytes(s.storage_bytes, l)} />
                <StatCard label={t('admin.stats.ai_requests')} value={formatNumber(s.ai_requests_30_days, l)} hint={t('admin.stats.last_30')} />
                <StatCard label={t('admin.stats.ai_cost')} value={formatUsd(s.ai_cost_usd_30_days, l)} hint={t('admin.stats.estimate_30')} />
            </div>

            <Card className="mt-8" padded={false}>
                <div className="border-b border-stone-100 px-6 py-4">
                    <h2 className="font-semibold">{t('admin.dashboard.failures')}</h2>
                </div>
                {data.recent_failures.length === 0 ? (
                    <p className="px-6 py-10 text-center text-sm text-stone-500">{t('admin.dashboard.no_failures')}</p>
                ) : (
                    <ul className="divide-y divide-stone-100">
                        {data.recent_failures.map((f) => (
                            <li key={f.id} className="flex flex-col gap-1 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <Link to={`/admin/companies/${f.company?.id}`} className="font-medium hover:underline">
                                        {f.company?.name}
                                    </Link>
                                    <p className="text-sm text-stone-500">{f.error_message || f.error_code}</p>
                                </div>
                                <span className="text-xs text-stone-400">{formatDate(f.created_at, l)}</span>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>
        </div>
    );
}

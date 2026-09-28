import { Link } from 'react-router';
import { useTranslation } from 'react-i18next';
import { ChevronRight, ImageIcon, ImagePlus, Images, Settings } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { useFetch } from '../../lib/useFetch';
import { usePolling } from '../../lib/usePolling';
import { formatBytes, formatDate, formatNumber } from '../../lib/format';
import BatchStatusBadge, { ACTIVE_STATUSES } from '../../components/batch/BatchStatusBadge';
import { Button, Card, EmptyState, StatCard } from '../../components/ui';

/** Company dashboard: one clear main action, recent batches and a few numbers. */
export default function Dashboard() {
    const { t, i18n } = useTranslation();
    const { user, meta } = useAuth();
    const { data: stats } = useFetch('/company/stats');
    const { data: batches, reload } = useFetch('/company/batches', { per_page: 6 });
    const l = i18n.language;

    usePolling(reload, 5000, !!batches?.some((b) => ACTIVE_STATUSES.includes(b.status)));

    return (
        <div className="space-y-10">
            <section className="rounded-3xl bg-white px-6 py-10 text-center ring-1 ring-stone-200/80 sm:px-10 sm:py-14">
                <p className="text-sm font-medium text-brand-700">{user.company?.name}</p>
                <h1 className="mt-2 text-3xl font-semibold tracking-tight sm:text-4xl">{t('dashboard.hero_title')}</h1>
                <p className="mx-auto mt-3 max-w-xl text-stone-500">{t('dashboard.hero_text', { max: meta?.max_images_per_batch ?? 30 })}</p>
                <div className="mt-8 flex justify-center">
                    <Button size="lg" icon={ImagePlus} to="/batches/new" className="w-full sm:w-auto">
                        {t('dashboard.optimize')}
                    </Button>
                </div>
            </section>

            <section className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatCard label={t('dashboard.stats.images_30')} value={formatNumber(stats?.images_last_30_days, l)} />
                <StatCard label={t('dashboard.stats.active_batches')} value={formatNumber(stats?.batches_active, l)} />
                <StatCard label={t('dashboard.stats.storage')} value={formatBytes(stats?.storage_bytes, l)} />
                <StatCard label={t('dashboard.stats.retention')} value={t('dashboard.days', { count: meta?.retention_days ?? 7 })} />
            </section>

            <section className="grid gap-4 md:grid-cols-3">
                <Card className="md:col-span-2" padded={false}>
                    <div className="flex items-center justify-between border-b border-stone-100 px-6 py-4">
                        <h2 className="font-semibold">{t('dashboard.recent')}</h2>
                        <Link to="/history" className="text-sm font-medium text-brand-700">
                            {t('history.all')}
                        </Link>
                    </div>
                    {!batches?.length ? (
                        <EmptyState icon={Images} title={t('dashboard.no_batches')} description={t('dashboard.no_batches_text')} />
                    ) : (
                        <ul className="divide-y divide-stone-100">
                            {batches.map((b) => (
                                <li key={b.id}>
                                    <Link to={b.status === 'draft' ? `/batches/${b.id}/edit` : `/batches/${b.id}`} className="flex items-center gap-4 px-4 py-3 hover:bg-stone-50 sm:px-6">
                                        <div className="size-14 shrink-0 overflow-hidden rounded-xl bg-stone-100">
                                            {b.cover_url ? (
                                                <img src={b.cover_url} alt="" loading="lazy" className="size-full object-cover" />
                                            ) : (
                                                <div className="grid size-full place-items-center text-stone-400">
                                                    <ImageIcon className="size-5" aria-hidden />
                                                </div>
                                            )}
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="truncate font-medium">{b.name || t('batch.unnamed')}</span>
                                                <BatchStatusBadge status={b.status} />
                                            </div>
                                            <p className="text-sm text-stone-500">
                                                {t('batch.photo_count_short', { count: b.images_count })} · {formatDate(b.created_at, l)}
                                            </p>
                                        </div>
                                        <ChevronRight className="size-5 shrink-0 text-stone-300" aria-hidden />
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
                <Card>
                    <h2 className="font-semibold">{t('dashboard.settings_title')}</h2>
                    <p className="mt-1 text-sm text-stone-500">{t('dashboard.settings_text')}</p>
                    <div className="mt-4 flex items-center gap-3 rounded-xl bg-stone-50 p-3">
                        {user.company?.logo_url ? (
                            <img src={user.company.logo_url} alt={t('logo.title')} className="h-10 max-w-32 object-contain" />
                        ) : (
                            <span className="text-sm text-stone-500">{t('logo.none_dashboard')}</span>
                        )}
                    </div>
                    <Button variant="secondary" icon={Settings} className="mt-5 w-full" to="/settings">
                        {t('nav.settings')}
                    </Button>
                </Card>
            </section>
        </div>
    );
}

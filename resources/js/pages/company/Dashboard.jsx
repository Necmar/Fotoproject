import { useTranslation } from 'react-i18next';
import { ImagePlus, Images, Settings } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { useFetch } from '../../lib/useFetch';
import { formatBytes, formatNumber } from '../../lib/format';
import { Button, Card, EmptyState, StatCard } from '../../components/ui';

/**
 * Company dashboard. The main action "Foto's optimaliseren" becomes active
 * in phase 2 (upload flow); recent batches follow in phase 8.
 */
export default function Dashboard() {
    const { t, i18n } = useTranslation();
    const { user, meta } = useAuth();
    const { data: stats } = useFetch('/company/stats');

    return (
        <div className="space-y-10">
            <section className="rounded-3xl bg-white px-6 py-10 text-center ring-1 ring-stone-200/80 sm:px-10 sm:py-14">
                <p className="text-sm font-medium text-brand-700">{user.company?.name}</p>
                <h1 className="mt-2 text-3xl font-semibold tracking-tight sm:text-4xl">{t('dashboard.hero_title')}</h1>
                <p className="mx-auto mt-3 max-w-xl text-stone-500">
                    {t('dashboard.hero_text', { max: meta?.max_images_per_batch ?? 30 })}
                </p>
                <div className="mt-8 flex justify-center">
                    <Button size="lg" icon={ImagePlus} disabled title={t('dashboard.coming_soon')}>
                        {t('dashboard.optimize')}
                    </Button>
                </div>
                <p className="mt-3 text-xs text-stone-400">{t('dashboard.coming_soon')}</p>
            </section>

            <section className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatCard label={t('dashboard.stats.images_30')} value={formatNumber(stats?.images_last_30_days, i18n.language)} />
                <StatCard label={t('dashboard.stats.active_batches')} value={formatNumber(stats?.batches_active, i18n.language)} />
                <StatCard label={t('dashboard.stats.storage')} value={formatBytes(stats?.storage_bytes, i18n.language)} />
                <StatCard label={t('dashboard.stats.retention')} value={t('dashboard.days', { count: meta?.retention_days ?? 7 })} />
            </section>

            <section className="grid gap-4 md:grid-cols-3">
                <Card className="md:col-span-2" padded={false}>
                    <div className="border-b border-stone-100 px-6 py-4">
                        <h2 className="font-semibold">{t('dashboard.recent')}</h2>
                    </div>
                    <EmptyState icon={Images} title={t('dashboard.no_batches')} description={t('dashboard.no_batches_text')} />
                </Card>
                <Card>
                    <h2 className="font-semibold">{t('dashboard.settings_title')}</h2>
                    <p className="mt-1 text-sm text-stone-500">{t('dashboard.settings_text')}</p>
                    <Button variant="secondary" icon={Settings} className="mt-5 w-full" to="/settings">
                        {t('nav.settings')}
                    </Button>
                </Card>
            </section>
        </div>
    );
}

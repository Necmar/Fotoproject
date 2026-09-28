import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { CircleAlert, CircleCheck, CircleX, ChevronDown } from 'lucide-react';
import { useFetch } from '../../lib/useFetch';
import { Badge, Card, cx, Spinner } from '../ui';

const ICONS = {
    ok: [CircleCheck, 'text-emerald-600'],
    warning: [CircleAlert, 'text-amber-600'],
    error: [CircleX, 'text-red-600'],
};

/**
 * Installation check (Super Admin). Problems are listed with a concrete fix;
 * everything that is fine stays folded away. Made for hosting without SSH.
 */
export default function HealthCard() {
    const { t } = useTranslation();
    const { data, loading, error } = useFetch('/admin/health');
    const [showAll, setShowAll] = useState(false);

    if (error) return null;

    const checks = data?.checks ?? [];
    const problems = checks.filter((c) => c.status !== 'ok');
    const visible = showAll ? checks : problems;
    const tone = { ok: 'green', warning: 'amber', error: 'red' }[data?.status] ?? 'grey';

    return (
        <Card className="mb-6">
            <div className="flex items-center justify-between gap-3">
                <h2 className="font-semibold">{t('admin.health.title')}</h2>
                {loading ? <Spinner /> : <Badge tone={tone}>{t(`admin.health.status.${data.status}`)}</Badge>}
            </div>

            {!loading && (
                <>
                    {problems.length === 0 && !showAll && <p className="mt-2 text-sm text-stone-500">{t('admin.health.all_ok', { count: checks.length })}</p>}
                    {visible.length > 0 && (
                        <ul className="mt-4 divide-y divide-stone-100">
                            {visible.map((c) => {
                                const [Icon, color] = ICONS[c.status];
                                return (
                                    <li key={c.key} className="flex gap-3 py-3">
                                        <Icon className={cx('mt-0.5 size-5 shrink-0', color)} aria-label={t(`admin.health.status.${c.status}`)} />
                                        <div className="min-w-0 text-sm">
                                            <p className="font-medium">
                                                {c.label}
                                                {c.value && <span className="ml-2 font-normal break-all text-stone-500">{c.value}</span>}
                                            </p>
                                            {c.hint && <p className="mt-0.5 text-stone-600">{c.hint}</p>}
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                    <button type="button" onClick={() => setShowAll(!showAll)} className="mt-3 inline-flex h-9 items-center gap-1 text-sm font-medium text-brand-700">
                        <ChevronDown className={cx('size-4 transition', showAll && 'rotate-180')} aria-hidden />
                        {showAll ? t('admin.health.show_problems') : t('admin.health.show_all')}
                    </button>
                </>
            )}
        </Card>
    );
}

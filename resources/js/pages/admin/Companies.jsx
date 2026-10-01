import { useEffect, useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router';
import { useTranslation } from 'react-i18next';
import { Building2, Plus, Search } from 'lucide-react';
import { useFetch } from '../../lib/useFetch';
import { formatBytes, formatDate, formatNumber } from '../../lib/format';
import { Alert, Badge, Button, Card, EmptyState, PageHeader, Pagination, Spinner } from '../../components/ui';

export function CompanyStatus({ status }) {
    const { t } = useTranslation();
    return <Badge tone={status === 'active' ? 'green' : 'red'}>{t(`enums.company_status.${status}`)}</Badge>;
}

export default function Companies() {
    const { t, i18n } = useTranslation();
    const [search, setSearch] = useState('');
    const [query, setQuery] = useState({ page: 1, search: '', status: '' });
    const { data, meta, loading, error } = useFetch('/admin/companies', query);
    const location = useLocation();
    const navigate = useNavigate();
    const [notice, setNotice] = useState(location.state?.notice ?? null);
    const l = i18n.language;

    // A notice from another page (e.g. "company deleted") is shown once.
    useEffect(() => {
        if (location.state?.notice) navigate(location.pathname, { replace: true, state: null });
    }, []); // eslint-disable-line react-hooks/exhaustive-deps

    const submit = (e) => {
        e.preventDefault();
        setQuery({ ...query, page: 1, search });
    };

    return (
        <div>
            <PageHeader
                title={t('admin.companies.title')}
                description={t('admin.companies.subtitle')}
                actions={
                    <Button icon={Plus} to="/admin/companies/new">
                        {t('admin.companies.new')}
                    </Button>
                }
            />

            <form onSubmit={submit} className="mb-5 flex flex-col gap-3 sm:flex-row">
                <div className="relative flex-1">
                    <Search className="pointer-events-none absolute top-3.5 left-3.5 size-4 text-stone-400" aria-hidden />
                    <input
                        type="search"
                        value={search}
                        onChange={(e) => {
                            setSearch(e.target.value);
                            // Clearing the field (also the "x" of a search input) shows everything again.
                            if (e.target.value === '' && query.search !== '') setQuery({ ...query, page: 1, search: '' });
                        }}
                        placeholder={t('admin.companies.search')}
                        className="h-11 w-full rounded-xl border border-stone-200 bg-white pr-3 pl-10 text-base focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 focus:outline-none sm:text-sm"
                    />
                </div>
                <select
                    value={query.status}
                    onChange={(e) => setQuery({ ...query, page: 1, search, status: e.target.value })}
                    className="h-11 rounded-xl border border-stone-200 bg-white px-3 text-sm"
                    aria-label={t('fields.status')}
                >
                    <option value="">{t('admin.companies.all_statuses')}</option>
                    <option value="active">{t('enums.company_status.active')}</option>
                    <option value="blocked">{t('enums.company_status.blocked')}</option>
                </select>
                <Button type="submit" variant="secondary">
                    {t('common.search')}
                </Button>
            </form>

            {notice && (
                <Alert type="success" onClose={() => setNotice(null)} className="mb-5">
                    {notice}
                </Alert>
            )}
            <Alert type="error">{error}</Alert>

            <Card padded={false}>
                {loading && !data ? (
                    <div className="p-10">
                        <Spinner />
                    </div>
                ) : data?.length === 0 ? (
                    <EmptyState icon={Building2} title={t('admin.companies.empty')} />
                ) : (
                    <ul className="divide-y divide-stone-100">
                        {data?.map((c) => (
                            <li key={c.id}>
                                <Link to={`/admin/companies/${c.id}`} className="grid gap-2 px-5 py-4 hover:bg-stone-50 sm:grid-cols-12 sm:items-center sm:px-6">
                                    <div className="sm:col-span-5">
                                        <div className="flex items-center gap-2">
                                            <span className="font-medium text-stone-900">{c.name}</span>
                                            <CompanyStatus status={c.status} />
                                        </div>
                                        <p className="truncate text-sm text-stone-500">
                                            {c.owner?.name} · {c.owner?.email}
                                        </p>
                                    </div>
                                    <div className="text-sm text-stone-500 sm:col-span-3">
                                        {c.owner?.last_login_at ? t('admin.companies.last_login', { date: formatDate(c.owner.last_login_at, l) }) : t('admin.companies.never_logged_in')}
                                    </div>
                                    <div className="flex gap-4 text-sm text-stone-500 sm:col-span-4 sm:justify-end">
                                        <span>{t('admin.companies.images', { count: c.stats.images_processed_total, formatted: formatNumber(c.stats.images_processed_total, l) })}</span>
                                        <span>{formatBytes(c.stats.storage_bytes, l)}</span>
                                    </div>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            <Pagination
                meta={meta}
                onPage={(page) => setQuery({ ...query, page })}
                labels={{
                    summary: t('common.page_of', { page: meta?.current_page, total: meta?.last_page }),
                    previous: t('common.previous'),
                    next: t('common.next'),
                }}
            />
        </div>
    );
}

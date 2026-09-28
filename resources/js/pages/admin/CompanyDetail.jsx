import { useEffect, useState } from 'react';
import { useLocation, useNavigate, useParams } from 'react-router';
import { useTranslation } from 'react-i18next';
import { Ban, CheckCircle2, HardDrive, KeyRound, Trash2 } from 'lucide-react';
import api, { errorMessage, fieldErrors } from '../../lib/api';
import { useFetch } from '../../lib/useFetch';
import { formatBytes, formatDate, formatNumber, formatUsd } from '../../lib/format';
import { Alert, Button, Card, Input, Modal, PageHeader, Select, Spinner, StatCard } from '../../components/ui';
import { CompanyStatus } from './Companies';

export default function CompanyDetail() {
    const { t, i18n } = useTranslation();
    const { id } = useParams();
    const location = useLocation();
    const navigate = useNavigate();
    const { body, loading, error, reload } = useFetch(`/admin/companies/${id}`);
    const [notice, setNotice] = useState(location.state?.created ? { type: 'success', text: t('admin.companies.created') } : null);
    const [dialog, setDialog] = useState(null);
    const l = i18n.language;

    if (loading && !body) return <Spinner />;
    if (error) return <Alert type="error">{error}</Alert>;

    const company = body.data;
    const stats = body.meta.stats;

    const run = async (fn, successText) => {
        try {
            const res = await fn();
            setNotice({ type: 'success', text: successText ?? res.data.message ?? t('common.saved') });
            setDialog(null);
            reload();
        } catch (err) {
            setNotice({ type: 'error', text: errorMessage(err) });
            setDialog(null);
        }
    };

    return (
        <div>
            <PageHeader
                title={
                    <span className="flex flex-wrap items-center gap-3">
                        {company.name} <CompanyStatus status={company.status} />
                    </span>
                }
                description={t('admin.companies.created_at', { date: formatDate(company.created_at, l, false) })}
            />

            {notice && (
                <Alert type={notice.type} onClose={() => setNotice(null)} className="mb-6">
                    {notice.text}
                </Alert>
            )}
            {company.status === 'blocked' && (
                <Alert type="warning" className="mb-6">
                    {t('admin.companies.blocked_since', { date: formatDate(company.blocked_at, l) })}
                    {company.blocked_reason && ` (${company.blocked_reason})`}
                </Alert>
            )}

            <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatCard label={t('admin.stats.batches')} value={formatNumber(stats.batches_current, l)} hint={t('admin.stats.batches_total', { count: stats.batches_total })} />
                <StatCard label={t('admin.stats.images_processed')} value={formatNumber(stats.images_processed_total, l)} hint={t('admin.stats.failed_total', { count: stats.images_failed_total })} />
                <StatCard label={t('admin.stats.storage')} value={formatBytes(stats.storage_bytes, l)} />
                <StatCard label={t('admin.stats.ai_cost_total')} value={formatUsd(stats.ai_cost_usd, l)} hint={t('admin.stats.ai_requests_count', { count: stats.ai_requests })} />
            </div>

            <div className="mt-8 grid gap-6 lg:grid-cols-3">
                <div className="lg:col-span-2">
                    <EditForm company={company} onSaved={(text) => { setNotice({ type: 'success', text }); reload(); }} />
                </div>

                <div className="space-y-6">
                    <Card>
                        <h2 className="font-semibold">{t('admin.companies.account')}</h2>
                        <dl className="mt-4 space-y-3 text-sm">
                            <div>
                                <dt className="text-stone-500">{t('admin.companies.email_status')}</dt>
                                <dd>{company.owner?.email_verified ? t('admin.companies.verified') : t('admin.companies.not_verified')}</dd>
                            </div>
                            <div>
                                <dt className="text-stone-500">{t('admin.companies.last_login_label')}</dt>
                                <dd>{company.owner?.last_login_at ? formatDate(company.owner.last_login_at, l) : t('admin.companies.never_logged_in')}</dd>
                            </div>
                        </dl>
                    </Card>

                    <Card className="space-y-2">
                        <h2 className="mb-3 font-semibold">{t('admin.companies.actions')}</h2>
                        <Button variant="secondary" icon={KeyRound} className="w-full justify-start" onClick={() => run(() => api.post(`/admin/companies/${id}/password-reset`))}>
                            {t('admin.companies.send_reset')}
                        </Button>
                        {company.status === 'active' ? (
                            <Button variant="secondary" icon={Ban} className="w-full justify-start" onClick={() => setDialog('block')}>
                                {t('admin.companies.block')}
                            </Button>
                        ) : (
                            <Button variant="secondary" icon={CheckCircle2} className="w-full justify-start" onClick={() => run(() => api.post(`/admin/companies/${id}/unblock`), t('admin.companies.unblocked'))}>
                                {t('admin.companies.unblock')}
                            </Button>
                        )}
                        <Button variant="secondary" icon={HardDrive} className="w-full justify-start" onClick={() => setDialog('purge')}>
                            {t('admin.companies.purge')}
                        </Button>
                        <Button variant="danger-ghost" icon={Trash2} className="w-full justify-start" onClick={() => setDialog('delete')}>
                            {t('admin.companies.delete')}
                        </Button>
                    </Card>
                </div>
            </div>

            <BlockDialog open={dialog === 'block'} onClose={() => setDialog(null)} onConfirm={(reason) => run(() => api.post(`/admin/companies/${id}/block`, { reason }), t('admin.companies.blocked'))} />
            <ConfirmDialog
                open={dialog === 'purge'}
                title={t('admin.companies.purge')}
                text={t('admin.companies.purge_text')}
                confirmLabel={t('admin.companies.purge_confirm')}
                onClose={() => setDialog(null)}
                onConfirm={() => run(() => api.delete(`/admin/companies/${id}/storage`))}
            />
            <DeleteDialog
                open={dialog === 'delete'}
                company={company}
                onClose={() => setDialog(null)}
                onDeleted={() => navigate('/admin/companies', { replace: true })}
            />
        </div>
    );
}

function EditForm({ company, onSaved }) {
    const { t } = useTranslation();
    const initial = { company_name: company.name, owner_name: company.owner?.name ?? '', email: company.owner?.email ?? '', locale: company.owner?.locale ?? 'nl', password: '' };
    const [form, setForm] = useState(initial);
    const [errors, setErrors] = useState({});
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => setForm(initial), [company.id]); // eslint-disable-line react-hooks/exhaustive-deps

    const set = (key) => (e) => setForm({ ...form, [key]: e.target.value });

    const submit = async (e) => {
        e.preventDefault();
        setBusy(true);
        setErrors({});
        setError(null);
        try {
            await api.put(`/admin/companies/${company.id}`, { ...form, password: form.password || null });
            setForm({ ...form, password: '' });
            onSaved(t('common.saved'));
        } catch (err) {
            setErrors(fieldErrors(err));
            if (err.response?.status !== 422) setError(errorMessage(err));
        } finally {
            setBusy(false);
        }
    };

    return (
        <Card>
            <h2 className="font-semibold">{t('admin.companies.details')}</h2>
            <form onSubmit={submit} className="mt-5 space-y-5">
                <Alert type="error">{error}</Alert>
                <div className="grid gap-5 sm:grid-cols-2">
                    <Input label={t('fields.company_name')} value={form.company_name} error={errors.company_name} onChange={set('company_name')} />
                    <Input label={t('fields.owner_name')} value={form.owner_name} error={errors.owner_name} onChange={set('owner_name')} />
                    <Input label={t('fields.email')} type="email" value={form.email} error={errors.email} hint={t('admin.companies.email_change_hint')} onChange={set('email')} />
                    <Select
                        label={t('common.language')}
                        value={form.locale}
                        options={[
                            { value: 'nl', label: 'Nederlands' },
                            { value: 'en', label: 'English' },
                        ]}
                        onChange={set('locale')}
                    />
                    <div className="sm:col-span-2">
                        <Input
                            label={t('admin.companies.new_password')}
                            type="text"
                            autoComplete="off"
                            hint={t('admin.companies.new_password_hint')}
                            value={form.password}
                            error={errors.password}
                            onChange={set('password')}
                        />
                    </div>
                </div>
                <div className="flex justify-end">
                    <Button type="submit" loading={busy}>
                        {t('common.save')}
                    </Button>
                </div>
            </form>
        </Card>
    );
}

function BlockDialog({ open, onClose, onConfirm }) {
    const { t } = useTranslation();
    const [reason, setReason] = useState('');
    return (
        <Modal
            open={open}
            onClose={onClose}
            title={t('admin.companies.block')}
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button variant="danger" onClick={() => onConfirm(reason)}>
                        {t('admin.companies.block')}
                    </Button>
                </>
            }
        >
            <p className="text-sm text-stone-600">{t('admin.companies.block_text')}</p>
            <Input label={t('admin.companies.block_reason')} value={reason} onChange={(e) => setReason(e.target.value)} />
        </Modal>
    );
}

function ConfirmDialog({ open, title, text, confirmLabel, onClose, onConfirm }) {
    const { t } = useTranslation();
    const [busy, setBusy] = useState(false);
    return (
        <Modal
            open={open}
            onClose={onClose}
            title={title}
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button variant="danger" loading={busy} onClick={async () => { setBusy(true); await onConfirm(); setBusy(false); }}>
                        {confirmLabel}
                    </Button>
                </>
            }
        >
            <p className="text-sm text-stone-600">{text}</p>
        </Modal>
    );
}

function DeleteDialog({ open, company, onClose, onDeleted }) {
    const { t } = useTranslation();
    const [name, setName] = useState('');
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    const remove = async () => {
        setBusy(true);
        setError(null);
        try {
            await api.delete(`/admin/companies/${company.id}`, { data: { confirm_name: name } });
            onDeleted();
        } catch (err) {
            setError(fieldErrors(err).confirm_name ?? errorMessage(err));
        } finally {
            setBusy(false);
        }
    };

    return (
        <Modal
            open={open}
            onClose={onClose}
            title={t('admin.companies.delete')}
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button variant="danger" loading={busy} disabled={name !== company.name} onClick={remove}>
                        {t('admin.companies.delete_confirm')}
                    </Button>
                </>
            }
        >
            <p className="text-sm text-stone-600">{t('admin.companies.delete_text', { name: company.name })}</p>
            <Input label={t('admin.companies.type_name')} value={name} error={error} onChange={(e) => setName(e.target.value)} autoComplete="off" />
        </Modal>
    );
}

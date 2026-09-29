import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Mail, Send } from 'lucide-react';
import api, { errorMessage, fieldErrors } from '../../lib/api';
import { useFetch } from '../../lib/useFetch';
import { useAuth } from '../../auth/AuthContext';
import { Alert, Badge, Button, Card, Input, Select, Spinner, Toggle } from '../ui';

/**
 * Super Admin: e-mail on/off and the SMTP server. The password is write-only:
 * the server only says whether one is stored.
 */
export default function MailSettingsCard() {
    const { t } = useTranslation();
    const { reloadMeta } = useAuth();
    const { data, loading, error, reload } = useFetch('/admin/mail');
    const [form, setForm] = useState(null);
    const [errors, setErrors] = useState({});
    const [status, setStatus] = useState(null);
    const [busy, setBusy] = useState(null);

    useEffect(() => {
        if (data) setForm({ ...data, mail_enabled: data.toggle ?? data.enabled, smtp_password: '' });
    }, [data]);

    if (loading || !form) return <Card>{error ? <Alert type="error">{error}</Alert> : <Spinner />}</Card>;

    const set = (key) => (e) => setForm({ ...form, [key]: e.target.value });

    const save = async (e) => {
        e.preventDefault();
        setBusy('save');
        setErrors({});
        setStatus(null);
        try {
            const { smtp_password, ...rest } = form;
            await api.put('/admin/mail', { ...rest, smtp_password: smtp_password || null, smtp_port: form.smtp_port ? Number(form.smtp_port) : null });
            setStatus({ type: 'success', text: t('common.saved') });
            reload();
            reloadMeta?.();
        } catch (err) {
            setErrors(fieldErrors(err));
            if (err.response?.status !== 422) setStatus({ type: 'error', text: errorMessage(err) });
        } finally {
            setBusy(null);
        }
    };

    const test = async () => {
        setBusy('test');
        setStatus(null);
        try {
            const { data: res } = await api.post('/admin/mail/test');
            setStatus({ type: 'success', text: res.message });
        } catch (err) {
            setStatus({ type: 'error', text: errorMessage(err) });
        } finally {
            setBusy(null);
        }
    };

    return (
        <Card>
            <form onSubmit={save} className="space-y-5">
                <div className="flex items-center justify-between gap-3">
                    <h2 className="flex items-center gap-2 font-semibold">
                        <Mail className="size-4 text-stone-400" aria-hidden /> {t('admin.mail.title')}
                    </h2>
                    <Badge tone={data.enabled ? 'green' : 'grey'}>{data.enabled ? t('admin.mail.on') : t('admin.mail.off')}</Badge>
                </div>
                <p className="text-sm text-stone-500">{t('admin.mail.intro')}</p>
                {data.forced_by_env && <Alert type="info">{t('admin.mail.forced')}</Alert>}
                {status && <Alert type={status.type}>{status.text}</Alert>}

                <Toggle
                    label={t('admin.mail.enable')}
                    description={form.mail_enabled ? t('admin.mail.enable_on') : t('admin.mail.enable_off')}
                    checked={!!form.mail_enabled}
                    onChange={(v) => setForm({ ...form, mail_enabled: v })}
                />

                {form.mail_enabled && (
                    <div className="space-y-4 rounded-xl bg-stone-50 p-4">
                        <div className="grid gap-4 sm:grid-cols-[1fr_8rem]">
                            <Input label={t('admin.mail.host')} placeholder="mail.jouwdomein.nl" value={form.smtp_host ?? ''} error={errors.smtp_host} onChange={set('smtp_host')} autoComplete="off" />
                            <Input label={t('admin.mail.port')} type="number" inputMode="numeric" value={form.smtp_port ?? ''} error={errors.smtp_port} onChange={set('smtp_port')} />
                        </div>
                        <Select
                            label={t('admin.mail.encryption')}
                            value={form.smtp_encryption ?? 'tls'}
                            error={errors.smtp_encryption}
                            options={[
                                { value: 'tls', label: t('admin.mail.enc_tls') },
                                { value: 'ssl', label: t('admin.mail.enc_ssl') },
                                { value: 'none', label: t('admin.mail.enc_none') },
                            ]}
                            onChange={set('smtp_encryption')}
                        />
                        <Input label={t('admin.mail.username')} value={form.smtp_username ?? ''} error={errors.smtp_username} onChange={set('smtp_username')} autoComplete="off" />
                        <Input
                            label={t('admin.mail.password')}
                            type="password"
                            autoComplete="new-password"
                            value={form.smtp_password}
                            error={errors.smtp_password}
                            hint={data.password_set ? t('admin.mail.password_kept') : t('admin.mail.password_none')}
                            onChange={set('smtp_password')}
                        />
                        <Input label={t('admin.mail.from_address')} type="email" placeholder="noreply@jouwdomein.nl" value={form.mail_from_address ?? ''} error={errors.mail_from_address} onChange={set('mail_from_address')} />
                        <Input label={t('admin.mail.from_name')} value={form.mail_from_name ?? ''} error={errors.mail_from_name} onChange={set('mail_from_name')} />
                    </div>
                )}

                <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    {data.enabled && (
                        <Button type="button" variant="secondary" icon={Send} loading={busy === 'test'} onClick={test}>
                            {t('admin.mail.test')}
                        </Button>
                    )}
                    <Button type="submit" loading={busy === 'save'}>
                        {t('common.save')}
                    </Button>
                </div>
            </form>
        </Card>
    );
}

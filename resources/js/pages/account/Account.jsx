import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import api, { errorMessage, fieldErrors } from '../../lib/api';
import { useAuth } from '../../auth/AuthContext';
import { Alert, Button, Card, Input, PageHeader, Select } from '../../components/ui';

/** Profile and password, for company owners and the Super Admin. */
export default function Account() {
    const { t } = useTranslation();

    return (
        <div className="mx-auto max-w-2xl">
            <PageHeader title={t('account.title')} description={t('account.subtitle')} />
            <div className="space-y-6">
                <ProfileForm />
                <PasswordForm />
            </div>
        </div>
    );
}

function ProfileForm() {
    const { t } = useTranslation();
    const { user, setUser } = useAuth();
    const [form, setForm] = useState({ name: user.name, email: user.email, locale: user.locale });
    const [errors, setErrors] = useState({});
    const [status, setStatus] = useState(null);
    const [busy, setBusy] = useState(false);

    const submit = async (e) => {
        e.preventDefault();
        setBusy(true);
        setErrors({});
        setStatus(null);
        try {
            const { data } = await api.put('/account/profile', form);
            setUser(data.data);
            setStatus({
                type: 'success',
                text: data.data.email_verified ? t('common.saved') : t('account.email_changed'),
            });
        } catch (err) {
            setErrors(fieldErrors(err));
            if (err.response?.status !== 422) setStatus({ type: 'error', text: errorMessage(err) });
        } finally {
            setBusy(false);
        }
    };

    return (
        <Card>
            <h2 className="font-semibold">{t('account.profile')}</h2>
            <form onSubmit={submit} className="mt-5 space-y-5">
                {status && <Alert type={status.type}>{status.text}</Alert>}
                <Input label={t('fields.name')} autoComplete="name" value={form.name} error={errors.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
                <Input
                    label={t('fields.email')}
                    type="email"
                    autoComplete="email"
                    value={form.email}
                    error={errors.email}
                    hint={t('account.email_hint')}
                    onChange={(e) => setForm({ ...form, email: e.target.value })}
                />
                <Select
                    label={t('common.language')}
                    value={form.locale}
                    error={errors.locale}
                    options={[
                        { value: 'nl', label: 'Nederlands' },
                        { value: 'en', label: 'English' },
                    ]}
                    onChange={(e) => setForm({ ...form, locale: e.target.value })}
                />
                <div className="flex justify-end">
                    <Button type="submit" loading={busy}>
                        {t('common.save')}
                    </Button>
                </div>
            </form>
        </Card>
    );
}

function PasswordForm() {
    const { t } = useTranslation();
    const empty = { current_password: '', password: '', password_confirmation: '' };
    const [form, setForm] = useState(empty);
    const [errors, setErrors] = useState({});
    const [status, setStatus] = useState(null);
    const [busy, setBusy] = useState(false);

    const submit = async (e) => {
        e.preventDefault();
        setBusy(true);
        setErrors({});
        setStatus(null);
        try {
            const { data } = await api.put('/account/password', form);
            setForm(empty);
            setStatus({ type: 'success', text: data.message });
        } catch (err) {
            setErrors(fieldErrors(err));
            if (err.response?.status !== 422) setStatus({ type: 'error', text: errorMessage(err) });
        } finally {
            setBusy(false);
        }
    };

    const set = (key) => (e) => setForm({ ...form, [key]: e.target.value });

    return (
        <Card>
            <h2 className="font-semibold">{t('account.password')}</h2>
            <form onSubmit={submit} className="mt-5 space-y-5">
                {status && <Alert type={status.type}>{status.text}</Alert>}
                <Input
                    label={t('fields.current_password')}
                    type="password"
                    autoComplete="current-password"
                    value={form.current_password}
                    error={errors.current_password}
                    onChange={set('current_password')}
                />
                <Input
                    label={t('fields.new_password')}
                    type="password"
                    autoComplete="new-password"
                    hint={t('fields.password_hint')}
                    value={form.password}
                    error={errors.password}
                    onChange={set('password')}
                />
                <Input
                    label={t('fields.password_confirmation')}
                    type="password"
                    autoComplete="new-password"
                    value={form.password_confirmation}
                    onChange={set('password_confirmation')}
                />
                <div className="flex justify-end">
                    <Button type="submit" loading={busy}>
                        {t('account.change_password')}
                    </Button>
                </div>
            </form>
        </Card>
    );
}

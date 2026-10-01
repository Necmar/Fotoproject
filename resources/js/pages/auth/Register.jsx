import { useState } from 'react';
import { Link, Navigate, useNavigate } from 'react-router';
import { useTranslation } from 'react-i18next';
import api, { errorMessage, fieldErrors } from '../../lib/api';
import { useAuth } from '../../auth/AuthContext';
import { Alert, Button, Input } from '../../components/ui';
import AlreadySignedIn, { isAlreadyAuthenticated } from '../../components/AlreadySignedIn';

/** Only reachable when the Super Admin has enabled public registration. */
export default function Register() {
    const { t, i18n } = useTranslation();
    const { meta, setUser } = useAuth();
    const navigate = useNavigate();
    const [form, setForm] = useState({ company_name: '', owner_name: '', email: '', password: '', password_confirmation: '' });
    const [errors, setErrors] = useState({});
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);
    const [signedIn, setSignedIn] = useState(null);

    if (meta && !meta.registration_enabled) return <Navigate to="/login" replace />;

    const set = (key) => (e) => setForm({ ...form, [key]: e.target.value });

    const submit = async (e) => {
        e.preventDefault();
        setBusy(true);
        setErrors({});
        setError(null);
        try {
            const { data } = await api.post('/auth/register', { ...form, locale: i18n.language });
            setUser(data.data);
            navigate('/verify-email', { replace: true });
        } catch (err) {
            if (isAlreadyAuthenticated(err)) {
                setSignedIn(errorMessage(err));
                return;
            }
            const fields = fieldErrors(err);
            setErrors(fields);
            if (!Object.keys(fields).length) setError(errorMessage(err));
        } finally {
            setBusy(false);
        }
    };

    return (
        <div>
            <h1 className="text-2xl font-semibold tracking-tight">{t('auth.register.title')}</h1>
            <p className="mt-1 text-stone-500">{t('auth.register.subtitle')}</p>
            <Alert type="error" className="mt-6">
                {error}
            </Alert>
            {signedIn && <AlreadySignedIn message={signedIn} onSignedOut={() => setSignedIn(null)} className="mt-6" />}
            <form onSubmit={submit} className="mt-6 space-y-5" noValidate>
                <Input label={t('fields.company_name')} autoComplete="organization" value={form.company_name} error={errors.company_name} onChange={set('company_name')} />
                <Input label={t('fields.owner_name')} autoComplete="name" value={form.owner_name} error={errors.owner_name} onChange={set('owner_name')} />
                <Input label={t('fields.email')} type="email" autoComplete="email" value={form.email} error={errors.email} onChange={set('email')} />
                <Input
                    label={t('fields.password')}
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
                <Button type="submit" size="lg" className="w-full" loading={busy}>
                    {t('auth.register.submit')}
                </Button>
            </form>
            <p className="mt-6 text-center text-sm">
                <Link to="/login" className="font-medium text-brand-700">
                    {t('auth.back_to_login')}
                </Link>
            </p>
        </div>
    );
}

import { useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router';
import { useTranslation } from 'react-i18next';
import api, { errorMessage, fieldErrors } from '../../lib/api';
import { Alert, Button, Input } from '../../components/ui';

/**
 * Used for both "reset password" (/reset-password/:token) and for the
 * invitation of a new company owner (/welcome/:token, longer valid link).
 */
export default function ResetPassword({ invite = false }) {
    const { t } = useTranslation();
    const { token } = useParams();
    const [params] = useSearchParams();
    const [form, setForm] = useState({ email: params.get('email') ?? '', password: '', password_confirmation: '' });
    const [errors, setErrors] = useState({});
    const [error, setError] = useState(null);
    const [done, setDone] = useState(false);
    const [busy, setBusy] = useState(false);

    const submit = async (e) => {
        e.preventDefault();
        setBusy(true);
        setErrors({});
        setError(null);
        try {
            await api.post('/auth/reset-password', { ...form, token, invite });
            setDone(true);
        } catch (err) {
            const fields = fieldErrors(err);
            setErrors(fields);
            if (!Object.keys(fields).length) setError(errorMessage(err));
        } finally {
            setBusy(false);
        }
    };

    const prefix = invite ? 'auth.welcome' : 'auth.reset';

    if (done) {
        return (
            <div className="space-y-6">
                <Alert type="success">{t(`${prefix}.done`)}</Alert>
                <Button to="/login" size="lg" className="w-full">
                    {t('auth.login.submit')}
                </Button>
            </div>
        );
    }

    return (
        <div>
            <h1 className="text-2xl font-semibold tracking-tight">{t(`${prefix}.title`)}</h1>
            <p className="mt-1 text-stone-500">{t(`${prefix}.subtitle`)}</p>

            <Alert type="error" className="mt-6">
                {error}
            </Alert>

            <form onSubmit={submit} className="mt-6 space-y-5" noValidate>
                <Input
                    label={t('fields.email')}
                    type="email"
                    autoComplete="username"
                    value={form.email}
                    error={errors.email}
                    onChange={(e) => setForm({ ...form, email: e.target.value })}
                />
                <Input
                    label={t('fields.new_password')}
                    type="password"
                    autoComplete="new-password"
                    hint={t('fields.password_hint')}
                    value={form.password}
                    error={errors.password}
                    onChange={(e) => setForm({ ...form, password: e.target.value })}
                />
                <Input
                    label={t('fields.password_confirmation')}
                    type="password"
                    autoComplete="new-password"
                    value={form.password_confirmation}
                    onChange={(e) => setForm({ ...form, password_confirmation: e.target.value })}
                />
                <Button type="submit" size="lg" className="w-full" loading={busy}>
                    {t(`${prefix}.submit`)}
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

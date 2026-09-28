import { useState } from 'react';
import { Link } from 'react-router';
import { useTranslation } from 'react-i18next';
import api, { errorMessage, fieldErrors } from '../../lib/api';
import { Alert, Button, Input } from '../../components/ui';

export default function ForgotPassword() {
    const { t } = useTranslation();
    const [email, setEmail] = useState('');
    const [errors, setErrors] = useState({});
    const [status, setStatus] = useState(null);
    const [busy, setBusy] = useState(false);

    const submit = async (e) => {
        e.preventDefault();
        setBusy(true);
        setErrors({});
        setStatus(null);
        try {
            const { data } = await api.post('/auth/forgot-password', { email });
            setStatus({ type: 'success', text: data.message });
        } catch (err) {
            setErrors(fieldErrors(err));
            if (err.response?.status !== 422) setStatus({ type: 'error', text: errorMessage(err) });
        } finally {
            setBusy(false);
        }
    };

    return (
        <div>
            <h1 className="text-2xl font-semibold tracking-tight">{t('auth.forgot.title')}</h1>
            <p className="mt-1 text-stone-500">{t('auth.forgot.subtitle')}</p>

            {status && (
                <Alert type={status.type} className="mt-6">
                    {status.text}
                </Alert>
            )}

            <form onSubmit={submit} className="mt-6 space-y-5" noValidate>
                <Input
                    label={t('fields.email')}
                    type="email"
                    autoComplete="email"
                    inputMode="email"
                    required
                    value={email}
                    error={errors.email}
                    onChange={(e) => setEmail(e.target.value)}
                />
                <Button type="submit" size="lg" className="w-full" loading={busy}>
                    {t('auth.forgot.submit')}
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

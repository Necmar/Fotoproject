import { useState } from 'react';
import { Navigate, useNavigate } from 'react-router';
import { useTranslation } from 'react-i18next';
import { MailCheck } from 'lucide-react';
import api, { errorMessage } from '../../lib/api';
import { homePathFor, useAuth } from '../../auth/AuthContext';
import { Alert, Button } from '../../components/ui';

/** Shown to signed-in owners whose e-mail address is not verified yet. */
export default function VerifyEmail() {
    const { t } = useTranslation();
    const { user, refresh, logout } = useAuth();
    const navigate = useNavigate();
    const [status, setStatus] = useState(null);
    const [busy, setBusy] = useState(false);

    if (user?.email_verified) return <Navigate to={homePathFor(user)} replace />;

    const resend = async () => {
        setBusy(true);
        try {
            const { data } = await api.post('/auth/email/verification-notification');
            setStatus({ type: 'success', text: data.message });
        } catch (err) {
            setStatus({ type: 'error', text: errorMessage(err) });
        } finally {
            setBusy(false);
        }
    };

    const check = async () => {
        const fresh = await refresh();
        if (fresh?.email_verified) navigate(homePathFor(fresh), { replace: true });
        else setStatus({ type: 'info', text: t('auth.verify.not_yet') });
    };

    return (
        <div className="text-center">
            <div className="mx-auto mb-5 grid size-14 place-items-center rounded-2xl bg-brand-50">
                <MailCheck className="size-7 text-brand-700" aria-hidden />
            </div>
            <h1 className="text-2xl font-semibold tracking-tight">{t('auth.verify.title')}</h1>
            <p className="mt-2 text-stone-500">{t('auth.verify.subtitle', { email: user?.email })}</p>

            {status && (
                <Alert type={status.type} className="mt-6 text-left">
                    {status.text}
                </Alert>
            )}

            <div className="mt-8 space-y-3">
                <Button size="lg" className="w-full" onClick={check}>
                    {t('auth.verify.check')}
                </Button>
                <Button variant="secondary" size="lg" className="w-full" loading={busy} onClick={resend}>
                    {t('auth.verify.resend')}
                </Button>
                <Button variant="ghost" className="w-full" onClick={() => logout().then(() => navigate('/login'))}>
                    {t('nav.logout')}
                </Button>
            </div>
        </div>
    );
}

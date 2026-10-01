import { useState } from 'react';
import { useNavigate } from 'react-router';
import { useTranslation } from 'react-i18next';
import { LayoutDashboard, LogOut } from 'lucide-react';
import { homePathFor, useAuth } from '../auth/AuthContext';
import { Alert, Button } from './ui';

/** True for the API's 409 "you are already signed in" answer to guest-only routes. */
export function isAlreadyAuthenticated(error) {
    return error?.response?.status === 409 && error.response.data?.code === 'already_authenticated';
}

/**
 * Shown when the server says this browser is already signed in (e.g. in
 * another tab): continue to the dashboard, or sign out and stay here.
 */
export default function AlreadySignedIn({ message, onSignedOut, className }) {
    const { t } = useTranslation();
    const { refresh, logout } = useAuth();
    const navigate = useNavigate();
    const [busy, setBusy] = useState(null);

    const toDashboard = async () => {
        setBusy('home');
        const user = await refresh();
        setBusy(null);
        navigate(homePathFor(user), { replace: true });
    };

    const signOut = async () => {
        setBusy('logout');
        try {
            await logout();
        } catch {
            // Signed out locally anyway.
        }
        setBusy(null);
        onSignedOut?.();
    };

    return (
        <div className={className}>
            <Alert type="info">{message || t('auth.already_signed_in')}</Alert>
            <div className="mt-3 flex flex-col gap-2 sm:flex-row">
                <Button icon={LayoutDashboard} loading={busy === 'home'} disabled={!!busy} onClick={toDashboard} className="flex-1">
                    {t('errors.home')}
                </Button>
                <Button variant="secondary" icon={LogOut} loading={busy === 'logout'} disabled={!!busy} onClick={signOut} className="flex-1">
                    {t('nav.logout')}
                </Button>
            </div>
        </div>
    );
}

import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Languages } from 'lucide-react';
import api from '../lib/api';
import { SUPPORTED_LOCALES } from '../lib/i18n';
import { useAuth } from '../auth/AuthContext';

/** Switches the UI language; for signed-in users the choice is saved on the account. */
export default function LanguageSwitcher({ className = '' }) {
    const { i18n, t } = useTranslation();
    const { user, setUser } = useAuth();
    const [busy, setBusy] = useState(false);

    const change = async (locale) => {
        i18n.changeLanguage(locale);
        if (!user) return;
        setBusy(true);
        try {
            const { data } = await api.put('/account/profile', { name: user.name, email: user.email, locale });
            setUser(data.data);
        } catch {
            // Keep the local choice even if saving fails.
        } finally {
            setBusy(false);
        }
    };

    return (
        <label className={`inline-flex items-center gap-1.5 text-sm text-stone-500 ${className}`}>
            <Languages className="size-4" aria-hidden />
            <span className="sr-only">{t('common.language')}</span>
            <select
                value={i18n.language}
                disabled={busy}
                onChange={(e) => change(e.target.value)}
                className="cursor-pointer rounded-lg bg-transparent py-1 pr-1 font-medium text-stone-600 hover:text-stone-900 focus:outline-none"
            >
                {SUPPORTED_LOCALES.map((l) => (
                    <option key={l} value={l}>
                        {l.toUpperCase()}
                    </option>
                ))}
            </select>
        </label>
    );
}

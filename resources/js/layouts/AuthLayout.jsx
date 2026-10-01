import { Suspense } from 'react';
import { Outlet } from 'react-router';
import { useTranslation } from 'react-i18next';
import { Sparkles } from 'lucide-react';
import LanguageSwitcher from '../components/LanguageSwitcher';
import { useAuth } from '../auth/AuthContext';
import { PageLoading } from '../components/ui';

export function Logo() {
    const { meta } = useAuth();
    return (
        <span className="inline-flex items-center gap-2 font-semibold tracking-tight text-stone-900">
            <span className="grid size-8 place-items-center rounded-xl bg-brand-600 text-white">
                <Sparkles className="size-4" aria-hidden />
            </span>
            {meta?.app_name || 'Bora Foto'}
        </span>
    );
}

/** Centered, quiet layout for sign-in and password pages. */
export default function AuthLayout() {
    const { t } = useTranslation();

    return (
        <div className="flex min-h-dvh flex-col px-4 py-6 sm:px-6">
            <header className="mx-auto flex w-full max-w-md items-center justify-between">
                <Logo />
                <LanguageSwitcher />
            </header>
            <main className="mx-auto flex w-full max-w-md flex-1 flex-col justify-center py-10">
                <Suspense fallback={<PageLoading />}>
                    <Outlet />
                </Suspense>
            </main>
            <footer className="text-center text-xs text-stone-400">{t('auth.footer')}</footer>
        </div>
    );
}

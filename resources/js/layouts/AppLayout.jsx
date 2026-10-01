import { Suspense, useEffect, useState } from 'react';
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router';
import { useTranslation } from 'react-i18next';
import { LogOut, Menu, UserRound, X } from 'lucide-react';
import { Logo } from './AuthLayout';
import LanguageSwitcher from '../components/LanguageSwitcher';
import { useAuth } from '../auth/AuthContext';
import { Alert, PageLoading, cx } from '../components/ui';

/**
 * Shared shell for the company area and the Super Admin area.
 * Desktop: top navigation. Mobile: compact header with a slide-down menu.
 */
export default function AppLayout({ nav, badge }) {
    const { t } = useTranslation();
    const { user, logout, meta } = useAuth();
    const navigate = useNavigate();
    const location = useLocation();
    const [open, setOpen] = useState(false);

    useEffect(() => setOpen(false), [location.pathname]);

    const signOut = async () => {
        await logout();
        navigate('/login', { replace: true });
    };

    const linkClass = ({ isActive }) =>
        cx(
            'flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-medium transition-colors',
            isActive ? 'bg-stone-100 text-stone-900' : 'text-stone-500 hover:text-stone-900',
        );

    return (
        <div className="min-h-dvh">
            <header className="sticky top-0 z-20 border-b pt-[env(safe-area-inset-top)] border-stone-200/70 bg-stone-50 md:bg-stone-50/90 md:backdrop-blur">
                <div className="mx-auto flex h-16 max-w-6xl items-center gap-6 px-4 sm:px-6">
                    <NavLink to={nav[0].to} className="shrink-0">
                        <Logo />
                    </NavLink>
                    {badge && <span className="hidden rounded-full bg-stone-900 px-2.5 py-0.5 text-xs font-medium text-white sm:inline">{badge}</span>}

                    <nav className="hidden flex-1 items-center gap-1 md:flex" aria-label={t('nav.main')}>
                        {nav.map((item) => (
                            <NavLink key={item.to} to={item.to} end={item.end} className={linkClass}>
                                {item.label}
                            </NavLink>
                        ))}
                    </nav>

                    <div className="ml-auto hidden items-center gap-3 md:flex">
                        <LanguageSwitcher />
                        <NavLink to="/account" className={linkClass} title={user?.email}>
                            <UserRound className="size-4" aria-hidden />
                            <span className="max-w-40 truncate">{user?.name}</span>
                        </NavLink>
                        <button type="button" onClick={signOut} className="rounded-xl p-2 text-stone-500 hover:bg-stone-100 hover:text-stone-900" title={t('nav.logout')}>
                            <LogOut className="size-4" aria-hidden />
                            <span className="sr-only">{t('nav.logout')}</span>
                        </button>
                    </div>

                    <button
                        type="button"
                        className="ml-auto rounded-xl p-2.5 text-stone-600 hover:bg-stone-100 md:hidden"
                        onClick={() => setOpen((v) => !v)}
                        aria-expanded={open}
                        aria-label={t('nav.menu')}
                    >
                        {open ? <X className="size-5" /> : <Menu className="size-5" />}
                    </button>
                </div>

                {open && (
                    <nav className="border-t border-stone-200/70 px-4 pt-2 pb-4 md:hidden" aria-label={t('nav.main')}>
                        {nav.map((item) => (
                            <NavLink key={item.to} to={item.to} end={item.end} className={(s) => cx(linkClass(s), 'py-3 text-base')}>
                                {item.label}
                            </NavLink>
                        ))}
                        <NavLink to="/account" className={(s) => cx(linkClass(s), 'py-3 text-base')}>
                            {t('nav.account')}
                        </NavLink>
                        <div className="mt-2 flex items-center justify-between border-t border-stone-200/70 pt-3">
                            <LanguageSwitcher />
                            <button type="button" onClick={signOut} className="flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-medium text-stone-600">
                                <LogOut className="size-4" aria-hidden />
                                {t('nav.logout')}
                            </button>
                        </div>
                    </nav>
                )}
            </header>

            <main className="mx-auto max-w-6xl px-4 py-8 sm:px-6 sm:py-12">
                {meta?.maintenance_message && (
                    <Alert type="warning" className="mb-6">
                        {meta.maintenance_message}
                    </Alert>
                )}
                <Suspense fallback={<PageLoading />}>
                    <Outlet />
                </Suspense>
            </main>
        </div>
    );
}

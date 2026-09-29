import { Link } from 'react-router';
import { useTranslation } from 'react-i18next';

export default function NotFound() {
    const { t } = useTranslation();
    return (
        <div className="flex min-h-dvh flex-col items-center justify-center px-6 text-center">
            <p className="text-sm font-medium text-brand-700">404</p>
            <h1 className="mt-2 text-2xl font-semibold tracking-tight">{t('errors.not_found_title')}</h1>
            <p className="mt-2 text-stone-500">{t('errors.not_found_text')}</p>
            <Link to="/" className="mt-6 font-medium text-brand-700">
                {t('errors.back_home')}
            </Link>
        </div>
    );
}

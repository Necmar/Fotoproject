import { useEffect } from 'react';
import { useRouteError } from 'react-router';
import { useTranslation } from 'react-i18next';
import { RotateCcw, TriangleAlert } from 'lucide-react';
import { Button } from '../components/ui';

/** Shown instead of a white screen when a page throws while rendering. No technical details for users. */
export default function Crash() {
    const { t } = useTranslation();
    const error = useRouteError();

    useEffect(() => {
        console.error(error);
    }, [error]);

    return (
        <main className="grid min-h-dvh place-items-center px-4">
            <div className="max-w-sm text-center">
                <div className="mx-auto grid size-12 place-items-center rounded-full bg-amber-50 text-amber-700">
                    <TriangleAlert className="size-6" aria-hidden />
                </div>
                <h1 className="mt-4 text-xl font-semibold">{t('errors.crash_title')}</h1>
                <p className="mt-2 text-sm text-stone-600">{t('errors.crash_text')}</p>
                <div className="mt-6 flex flex-col gap-2">
                    <Button size="lg" icon={RotateCcw} onClick={() => window.location.reload()}>
                        {t('errors.reload')}
                    </Button>
                    <Button size="lg" variant="ghost" onClick={() => window.location.assign('/')}>
                        {t('errors.home')}
                    </Button>
                </div>
            </div>
        </main>
    );
}

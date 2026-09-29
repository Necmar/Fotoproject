import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ImageUp, Trash2 } from 'lucide-react';
import api, { errorMessage } from '../../lib/api';
import { useAuth } from '../../auth/AuthContext';
import { Alert, Button, Card } from '../ui';

/** Company logo for watermarks: upload, replace, remove. */
export default function LogoCard({ logoUrl, onChange }) {
    const { t } = useTranslation();
    const { refresh } = useAuth();
    const input = useRef(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);

    const run = async (request) => {
        setBusy(true);
        setError(null);
        try {
            const { data } = await request();
            onChange?.(data.data);
            refresh();
        } catch (err) {
            setError(err.response?.data?.errors?.logo?.[0] ?? errorMessage(err));
        } finally {
            setBusy(false);
        }
    };

    const upload = (e) => {
        const file = e.target.files?.[0];
        e.target.value = '';
        if (!file) return;
        const body = new FormData();
        body.append('logo', file);
        run(() => api.post('/company/logo', body));
    };

    return (
        <Card>
            <h2 className="font-semibold">{t('logo.title')}</h2>
            <p className="mt-1 text-sm text-stone-500">{t('logo.text')}</p>

            <div className="mt-5 flex flex-col gap-4 sm:flex-row sm:items-center">
                <div className="grid h-24 w-full place-items-center rounded-xl bg-[repeating-conic-gradient(#e7e5e4_0_25%,#fafaf9_0_50%)] bg-[length:16px_16px] ring-1 ring-stone-200 sm:w-48">
                    {logoUrl ? <img src={logoUrl} alt={t('logo.title')} className="max-h-20 max-w-[90%] object-contain" /> : <span className="text-sm text-stone-400">{t('logo.none')}</span>}
                </div>
                <div className="flex flex-col gap-2 sm:flex-row">
                    <Button icon={ImageUp} loading={busy} onClick={() => input.current?.click()}>
                        {logoUrl ? t('logo.replace') : t('logo.upload')}
                    </Button>
                    {logoUrl && (
                        <Button variant="danger-ghost" icon={Trash2} disabled={busy} onClick={() => run(() => api.delete('/company/logo'))}>
                            {t('logo.remove')}
                        </Button>
                    )}
                </div>
            </div>
            <Alert type="error" className="mt-4">
                {error}
            </Alert>
            <input ref={input} type="file" accept="image/png,image/jpeg" hidden onChange={upload} />
        </Card>
    );
}

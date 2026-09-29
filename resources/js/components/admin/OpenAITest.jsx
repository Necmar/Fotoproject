import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { CircleCheck, CircleX, PlugZap } from 'lucide-react';
import api, { errorMessage } from '../../lib/api';
import { formatDate } from '../../lib/format';
import { Alert, Button, cx } from '../ui';

/** Super Admin: last OpenAI error, and a live connection test with OpenAI's own messages. */
export default function OpenAITest({ lastError }) {
    const { t, i18n } = useTranslation();
    const [results, setResults] = useState(null);
    const [busy, setBusy] = useState(null);
    const [error, setError] = useState(null);

    const run = async (edit) => {
        setBusy(edit ? 'edit' : 'basic');
        setError(null);
        setResults(null);
        try {
            const { data } = await api.post('/admin/openai/test', { edit }, { timeout: 400000 });
            setResults(data.data);
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            setBusy(null);
        }
    };

    return (
        <div className="mt-5 space-y-4 border-t border-stone-100 pt-5">
            {lastError && (
                <div className="rounded-xl bg-red-50 p-4 text-sm text-red-900 ring-1 ring-red-200">
                    <p className="font-medium">{t('admin.openai_test.last_error', { date: formatDate(lastError.at, i18n.language) })}</p>
                    <p className="mt-1 break-words">
                        <span className="font-mono text-xs">{lastError.code}</span> · {lastError.model} · {lastError.message}
                    </p>
                </div>
            )}

            <div className="flex flex-col gap-2 sm:flex-row">
                <Button variant="secondary" icon={PlugZap} loading={busy === 'basic'} disabled={!!busy} onClick={() => run(false)}>
                    {t('admin.openai_test.run')}
                </Button>
                <Button variant="ghost" loading={busy === 'edit'} disabled={!!busy} onClick={() => run(true)}>
                    {t('admin.openai_test.run_edit')}
                </Button>
            </div>
            {busy && <p className="text-sm text-stone-500">{t('admin.openai_test.busy')}</p>}
            <Alert type="error">{error}</Alert>

            {results && (
                <ul className="divide-y divide-stone-100 rounded-xl ring-1 ring-stone-200">
                    {results.map((r) => (
                        <li key={r.step} className="flex gap-3 p-3 text-sm">
                            {r.ok ? <CircleCheck className="mt-0.5 size-5 shrink-0 text-emerald-600" aria-hidden /> : <CircleX className="mt-0.5 size-5 shrink-0 text-red-600" aria-hidden />}
                            <div className="min-w-0 flex-1">
                                <p className="font-medium">
                                    {t(`admin.openai_test.steps.${r.step}`)}
                                    {r.ms > 0 && <span className="ml-2 font-normal text-stone-400">{(r.ms / 1000).toFixed(1)} s</span>}
                                </p>
                                {r.message && <p className={cx('mt-0.5 break-words', r.ok ? 'text-stone-500' : 'text-red-700')}>{r.message}</p>}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

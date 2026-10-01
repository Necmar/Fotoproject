import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import api, { errorMessage } from '../../lib/api';
import { useAuth } from '../../auth/AuthContext';
import { Choice } from './BatchSettingsForm';
import { Alert } from '../ui';

/**
 * Step 5: watermark choice for downloads (shown in a dialog from the download
 * bar). The watermark is only added to downloads, so it can change any time.
 */
export default function DownloadPanel({ batch, onBatchChange }) {
    const { t } = useTranslation();
    const { user, meta } = useAuth();
    const hasLogo = !!user.company?.has_logo;
    // Local, optimistic copy: the UI follows clicks at once, and saves never undo each other.
    const pick = (b) => ({ watermark_mode: b.settings.watermark_mode, watermark_position: b.settings.watermark_position, watermark_opacity: b.settings.watermark_opacity });
    const [s, setS] = useState(() => pick(batch));
    const [opacity, setOpacity] = useState(s.watermark_opacity);
    const [error, setError] = useState(null);
    const [saving, setSaving] = useState(false);
    const latest = useRef(s);
    const saved = useRef(s);
    const running = useRef(false);

    // Follow server changes (e.g. polling) only while nothing is being saved.
    useEffect(() => {
        if (running.current) return;
        const next = pick(batch);
        latest.current = next;
        saved.current = next;
        setS(next);
        setOpacity(next.watermark_opacity);
    }, [batch.settings.watermark_mode, batch.settings.watermark_position, batch.settings.watermark_opacity]); // eslint-disable-line react-hooks/exhaustive-deps

    /** One request at a time; when it finishes, the newest choice is sent if it differs. */
    const flush = async () => {
        if (running.current) return;
        running.current = true;
        setSaving(true);
        setError(null);
        try {
            while (JSON.stringify(latest.current) !== JSON.stringify(saved.current)) {
                const sending = latest.current;
                const { data } = await api.put(`/company/batches/${batch.id}/watermark`, sending);
                saved.current = sending;
                if (latest.current === sending) onBatchChange(data.data);
            }
        } catch (err) {
            setError(errorMessage(err));
            // Back to what the server has.
            latest.current = saved.current;
            setS(saved.current);
            setOpacity(saved.current.watermark_opacity);
        } finally {
            running.current = false;
            setSaving(false);
        }
    };

    const save = (changes) => {
        const next = { ...latest.current, ...changes };
        latest.current = next;
        setS(next);
        flush();
    };

    const commitOpacity = (value) => {
        if (value !== latest.current.watermark_opacity) save({ watermark_opacity: value });
    };

    const opts = (key, group) => (meta?.options?.[key] ?? []).map((v) => ({ value: v, label: t(`enums.${group}.${v}`) }));

    return (
        <div className="space-y-5" aria-busy={saving}>
            <div className="space-y-4">
                {!hasLogo ? (
                    <p className="text-sm text-stone-500">{t('batch.settings.no_logo')}</p>
                ) : (
                    <>
                        <Choice label={t('fields.watermark_mode')} options={opts('watermark_mode', 'watermark_mode')} value={s.watermark_mode} onChange={(v) => save({ watermark_mode: v })} columns="grid-cols-1 sm:grid-cols-3" />
                        {s.watermark_mode !== 'none' && (
                            <>
                                <Choice label={t('fields.watermark_position')} options={opts('watermark_position', 'watermark_position')} value={s.watermark_position} onChange={(v) => save({ watermark_position: v })} columns="grid-cols-2 sm:grid-cols-5" />
                                <div>
                                    <label htmlFor="dl-opacity" className="block text-sm font-medium text-stone-700">
                                        {t('fields.watermark_opacity')}: {opacity}%
                                    </label>
                                    <input
                                        id="dl-opacity"
                                        type="range"
                                        min="10"
                                        max="100"
                                        step="5"
                                        value={opacity}
                                        onChange={(e) => setOpacity(Number(e.target.value))}
                                        onPointerUp={(e) => commitOpacity(Number(e.currentTarget.value))}
                                        onKeyUp={(e) => ['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End', 'PageUp', 'PageDown'].includes(e.key) && commitOpacity(Number(e.currentTarget.value))}
                                        onBlur={(e) => commitOpacity(Number(e.currentTarget.value))}
                                        className="mt-3 w-full accent-brand-600"
                                    />
                                </div>
                                {s.watermark_mode === 'selected' && <p className="text-sm text-stone-500">{t('download.select_hint')}</p>}
                                <p className="text-xs text-stone-400">{t('download.watermark_note')}</p>
                            </>
                        )}
                    </>
                )}
                <Alert type="error">{error}</Alert>
            </div>
        </div>
    );
}

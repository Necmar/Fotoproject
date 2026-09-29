import { useState } from 'react';
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
    const s = batch.settings;
    const [error, setError] = useState(null);
    const [saving, setSaving] = useState(false);

    const save = async (changes) => {
        setSaving(true);
        setError(null);
        try {
            const { data } = await api.put(`/company/batches/${batch.id}/watermark`, {
                watermark_mode: s.watermark_mode,
                watermark_position: s.watermark_position,
                watermark_opacity: s.watermark_opacity,
                ...changes,
            });
            onBatchChange(data.data);
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            setSaving(false);
        }
    };

    const opts = (key, group) => (meta?.options?.[key] ?? []).map((v) => ({ value: v, label: t(`enums.${group}.${v}`) }));

    return (
        <div className="space-y-5">
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
                                        {t('fields.watermark_opacity')}: {s.watermark_opacity}%
                                    </label>
                                    <input
                                        id="dl-opacity"
                                        type="range"
                                        min="10"
                                        max="100"
                                        step="5"
                                        defaultValue={s.watermark_opacity}
                                        disabled={saving}
                                        onPointerUp={(e) => save({ watermark_opacity: Number(e.currentTarget.value) })}
                                        onKeyUp={(e) => save({ watermark_opacity: Number(e.currentTarget.value) })}
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

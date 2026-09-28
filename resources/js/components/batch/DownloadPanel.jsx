import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Download } from 'lucide-react';
import api, { errorMessage } from '../../lib/api';
import { useAuth } from '../../auth/AuthContext';
import { Choice } from './BatchSettingsForm';
import { Alert, Button, Card } from '../ui';

/**
 * Step 5: download everything as ZIP, and choose the watermark. The
 * watermark is only added to downloads, so it can be changed at any time.
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
        <Card className="mb-8 space-y-5">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 className="font-semibold">{t('download.title')}</h2>
                    <p className="text-sm text-stone-500">{t('download.text', { count: batch.progress.completed })}</p>
                </div>
                <Button size="lg" icon={Download} onClick={() => (window.location.href = batch.download_url)} disabled={!batch.download_url}>
                    {t('download.zip')}
                </Button>
            </div>

            <div className="space-y-4 border-t border-stone-100 pt-5">
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
        </Card>
    );
}

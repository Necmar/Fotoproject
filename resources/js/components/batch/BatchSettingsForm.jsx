import { useTranslation } from 'react-i18next';
import { useAuth } from '../../auth/AuthContext';
import { Card, Input, Toggle, cx } from '../ui';

/** Row of large buttons for a small set of options (touch friendly). */
export function Choice({ label, options, value, onChange, columns = 'grid-cols-2 sm:grid-cols-3', describe }) {
    return (
        <fieldset>
            <legend className="mb-2 text-sm font-medium text-stone-700">{label}</legend>
            <div className={cx('grid gap-2', columns)}>
                {options.map((o) => {
                    const selected = o.value === value;
                    return (
                        <button
                            key={o.value}
                            type="button"
                            aria-pressed={selected}
                            disabled={o.disabled}
                            onClick={() => onChange(o.value)}
                            className={cx(
                                'min-h-12 rounded-xl px-3 py-2.5 text-left text-sm ring-1 transition disabled:cursor-not-allowed disabled:opacity-40',
                                selected ? 'bg-brand-50 font-medium text-brand-800 ring-2 ring-brand-500' : 'bg-white text-stone-700 ring-stone-200 hover:ring-stone-300',
                            )}
                        >
                            <span className="block">{o.label}</span>
                            {describe && o.description && <span className="mt-0.5 block text-xs font-normal text-stone-500">{o.description}</span>}
                        </button>
                    );
                })}
            </div>
        </fieldset>
    );
}

/**
 * Batch settings (step 2). `value` = { name, settings }.
 * Every setting applies to the whole batch; the AI still decides per photo
 * which corrections are needed.
 */
export default function BatchSettingsForm({ value, onChange, hasLogo, errors = {} }) {
    const { t } = useTranslation();
    const { meta } = useAuth();
    const s = value.settings;

    const opts = (key, group, withDescription = false) =>
        (meta?.options?.[key] ?? []).map((v) => ({
            value: v,
            label: t(`enums.${group}.${v}`),
            description: withDescription ? t(`batch.settings.descriptions.${group}.${v}`, { defaultValue: '' }) : undefined,
        }));

    const set = (key) => (v) => onChange({ ...value, settings: { ...s, [key]: v } });
    const ext = s.output_format === 'png' ? 'png' : 'jpg';
    const base = slugify(value.name) || value.fallbackBase || 'foto';

    return (
        <div className="space-y-6">
            <Card className="space-y-4">
                <Input
                    label={t('batch.settings.name')}
                    placeholder={t('batch.settings.name_placeholder')}
                    value={value.name ?? ''}
                    maxLength={120}
                    error={errors.name}
                    onChange={(e) => onChange({ ...value, name: e.target.value })}
                    hint={t('batch.settings.name_hint', { example: `${base}-01.${ext}, ${base}-02.${ext}` })}
                />
            </Card>

            <Card className="space-y-6">
                <h2 className="font-semibold">{t('batch.settings.output')}</h2>
                <Choice label={t('fields.output_format')} options={opts('output_format', 'output_format')} value={s.output_format} onChange={set('output_format')} columns="grid-cols-2" />
                <Choice label={t('fields.resolution')} options={opts('resolution', 'resolution')} value={s.resolution} onChange={set('resolution')} columns="grid-cols-1 sm:grid-cols-3" />
                <Choice label={t('fields.aspect_ratio')} options={opts('aspect_ratio', 'aspect_ratio')} value={s.aspect_ratio} onChange={set('aspect_ratio')} columns="grid-cols-3 sm:grid-cols-5" />
                {s.aspect_ratio !== 'original' && <p className="-mt-3 text-sm text-stone-500">{t('batch.settings.crop_hint')}</p>}
            </Card>

            <Card className="space-y-6">
                <h2 className="font-semibold">{t('batch.settings.optimization')}</h2>
                <Choice label={t('fields.strength')} options={opts('strength', 'optimization_strength', true)} value={s.strength} onChange={set('strength')} describe columns="grid-cols-1 sm:grid-cols-3" />
                <Choice label={t('fields.background')} options={opts('background', 'background_option')} value={s.background} onChange={set('background')} columns="grid-cols-1 sm:grid-cols-2" />
                <div className="rounded-xl bg-stone-50 p-4">
                    <Toggle label={t('batch.settings.remove_people')} description={t('batch.settings.remove_people_text')} checked={!!s.remove_people} onChange={set('remove_people')} />
                </div>
                <p className="text-sm text-stone-500">{t('batch.settings.integrity')}</p>
            </Card>

            <Card className="space-y-6">
                <h2 className="font-semibold">{t('settings.watermark')}</h2>
                {!hasLogo && <p className="text-sm text-stone-500">{t('batch.settings.no_logo')}</p>}
                <Choice
                    label={t('fields.watermark_mode')}
                    options={opts('watermark_mode', 'watermark_mode').map((o) => ({ ...o, disabled: !hasLogo && o.value !== 'none' }))}
                    value={s.watermark_mode}
                    onChange={(v) => (hasLogo || v === 'none') && set('watermark_mode')(v)}
                    columns="grid-cols-1 sm:grid-cols-3"
                />
                {hasLogo && s.watermark_mode !== 'none' && (
                    <>
                        <Choice label={t('fields.watermark_position')} options={opts('watermark_position', 'watermark_position')} value={s.watermark_position} onChange={set('watermark_position')} columns="grid-cols-2 sm:grid-cols-5" />
                        <div>
                            <label htmlFor="wm-opacity" className="block text-sm font-medium text-stone-700">
                                {t('fields.watermark_opacity')}: {s.watermark_opacity}%
                            </label>
                            <input
                                id="wm-opacity"
                                type="range"
                                min="10"
                                max="100"
                                step="5"
                                value={s.watermark_opacity}
                                onChange={(e) => set('watermark_opacity')(Number(e.target.value))}
                                className="mt-3 w-full accent-brand-600"
                            />
                        </div>
                    </>
                )}
            </Card>
        </div>
    );
}

/** Mirrors the server-side FileNamer for the live example only. */
export function slugify(text) {
    return (text ?? '')
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 60)
        .replace(/-+$/, '');
}

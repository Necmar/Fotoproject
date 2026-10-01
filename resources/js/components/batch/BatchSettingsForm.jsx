import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Brush, ChevronDown, Crop, Droplet, Eraser, Feather, Image, ImageUp, PenLine, Scissors, SlidersHorizontal, Sparkles, Square, Stamp, UserX, Wand2 } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import api, { errorMessage } from '../../lib/api';
import { Alert, Button, Card, Input, Toggle, cx } from '../ui';

const STRENGTH_ICONS = { subtle: Feather, normal: Sparkles, strong: Wand2 };
const BACKGROUND_ICONS = { keep: Image, clean_subtle: Brush, remove_distractions: Eraser, blur_light: Droplet, remove: Scissors, neutral: Square };
const RATIO_SHAPES = { original: null, '1:1': [16, 16], '4:3': [20, 15], '3:2': [21, 14], '16:9': [24, 13.5] };

/** Row of large buttons for a small set of options (touch friendly). Options may carry an icon or a visual. */
export function Choice({ label, options, value, onChange, columns = 'grid-cols-2 sm:grid-cols-3', describe, compact = false }) {
    return (
        <fieldset>
            {label && <legend className="mb-2 text-sm font-medium text-stone-700">{label}</legend>}
            <div className={cx('grid gap-2', columns)}>
                {options.map((o) => {
                    const selected = o.value === value;
                    const Icon = o.icon;
                    return (
                        <button
                            key={o.value}
                            type="button"
                            aria-pressed={selected}
                            disabled={o.disabled}
                            onClick={() => onChange(o.value)}
                            className={cx(
                                'flex min-h-12 items-start gap-3 rounded-xl px-3 text-left text-sm ring-1 transition disabled:cursor-not-allowed disabled:opacity-40',
                                compact ? 'py-2.5' : 'py-3',
                                selected ? 'bg-brand-50 font-medium text-brand-900 ring-2 ring-brand-500' : 'bg-white text-stone-700 ring-stone-200 hover:ring-stone-300',
                            )}
                        >
                            {Icon && (
                                <span className={cx('mt-0.5 grid size-8 shrink-0 place-items-center rounded-lg', selected ? 'bg-brand-600 text-white' : 'bg-stone-100 text-stone-500')}>
                                    <Icon className="size-4" aria-hidden />
                                </span>
                            )}
                            {o.visual}
                            <span className="min-w-0">
                                <span className="block">{o.label}</span>
                                {describe && o.description && <span className="mt-0.5 block text-xs font-normal text-stone-500">{o.description}</span>}
                            </span>
                        </button>
                    );
                })}
            </div>
        </fieldset>
    );
}

/** A card that can fold away behind a one-line summary. */
function Section({ icon: Icon, title, summary, collapsible = false, open: openProp, onOpenChange, defaultOpen = true, action, children }) {
    const { t } = useTranslation();
    const [openState, setOpenState] = useState(defaultOpen);
    const open = openProp ?? openState;
    const setOpen = onOpenChange ?? setOpenState;
    const shown = !collapsible || open;

    const head = (
        <>
            <span className="grid size-9 shrink-0 place-items-center rounded-xl bg-stone-100 text-stone-600">
                <Icon className="size-4.5" aria-hidden />
            </span>
            <span className="min-w-0 flex-1 text-left">
                <span className="block font-semibold">{title}</span>
                {collapsible && !open && summary && <span className="block truncate text-sm text-stone-500">{summary}</span>}
            </span>
        </>
    );

    return (
        <Card padded={false}>
            <div className="flex items-center gap-2 p-3 sm:p-4">
                {collapsible ? (
                    // The whole header opens and closes the section, not only the small link.
                    <button type="button" onClick={() => setOpen(!open)} aria-expanded={open} className="flex min-w-0 flex-1 items-center gap-3 rounded-xl p-2 hover:bg-stone-50">
                        {head}
                        <span className="inline-flex h-10 shrink-0 items-center gap-1 px-1 text-sm font-medium text-brand-700">
                            {open ? <ChevronDown className="size-4 rotate-180" aria-hidden /> : <PenLine className="size-4" aria-hidden />}
                            <span className="hidden sm:inline">{open ? t('batch.settings.collapse') : t('batch.settings.change')}</span>
                        </span>
                    </button>
                ) : (
                    <div className="flex min-w-0 flex-1 items-center gap-3 p-2">{head}</div>
                )}
                {action}
            </div>
            {shown && <div className="space-y-6 border-t border-stone-100 p-5">{children}</div>}
        </Card>
    );
}

/** Small on/off switch for a section header. */
function HeaderSwitch({ checked, onChange, label, busy }) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            aria-label={label}
            title={label}
            disabled={busy}
            onClick={() => onChange(!checked)}
            className="grid h-11 w-14 shrink-0 place-items-center rounded-xl disabled:opacity-60"
        >
            <span className={cx('relative h-6 w-11 rounded-full transition', checked ? 'bg-brand-600' : 'bg-stone-300')}>
                <span className={cx('absolute top-0.5 left-0.5 size-5 rounded-full bg-white shadow transition', checked && 'translate-x-5')} />
            </span>
        </button>
    );
}

function RatioShape({ ratio, selected }) {
    const size = RATIO_SHAPES[ratio];
    return (
        <span className="mt-0.5 grid size-8 shrink-0 place-items-center" aria-hidden>
            {size ? (
                <span className={cx('block rounded-[3px] border-2', selected ? 'border-brand-600' : 'border-stone-400')} style={{ width: size[0], height: size[1] }} />
            ) : (
                <Crop className={cx('size-4', selected ? 'text-brand-600' : 'text-stone-400')} />
            )}
        </span>
    );
}

/**
 * Batch settings (step 2). `value` = { name, settings }. The decisions that
 * matter most come first; output format and watermark fold away behind a
 * summary (their defaults come from the company settings).
 */
export default function BatchSettingsForm({ value, onChange, hasLogo, errors = {} }) {
    const { t } = useTranslation();
    const { meta, refresh } = useAuth();
    const s = value.settings;
    const [watermarkOpen, setWatermarkOpen] = useState(false);
    const [logoBusy, setLogoBusy] = useState(false);
    const [logoError, setLogoError] = useState(null);
    const logoInput = useRef(null);
    const watermarkOn = hasLogo && s.watermark_mode !== 'none';

    // Functional update: the logo upload finishes later, the form may have changed meanwhile.
    const setWatermarkMode = (mode) => onChange((f) => ({ ...f, settings: { ...f.settings, watermark_mode: mode } }));

    const toggleWatermark = (on) => {
        setLogoError(null);
        if (!on) return setWatermarkMode('none');
        setWatermarkOpen(true);
        if (hasLogo) return setWatermarkMode(s.watermark_mode === 'none' ? 'all' : s.watermark_mode);
        logoInput.current?.click(); // No logo yet: upload one right here.
    };

    const uploadLogo = async (e) => {
        const file = e.target.files?.[0];
        e.target.value = '';
        if (!file) return;
        const body = new FormData();
        body.append('logo', file);
        setLogoBusy(true);
        setLogoError(null);
        try {
            await api.post('/company/logo', body);
            await refresh(); // hasLogo becomes true before the mode is set
            setWatermarkMode('all');
        } catch (err) {
            setLogoError(err.response?.data?.errors?.logo?.[0] ?? errorMessage(err));
        } finally {
            setLogoBusy(false);
        }
    };

    const opts = (key, group, withDescription = false, icons = null) =>
        (meta?.options?.[key] ?? []).map((v) => ({
            value: v,
            label: t(`enums.${group}.${v}`),
            icon: icons?.[v],
            description: withDescription ? t(`batch.settings.descriptions.${group}.${v}`, { defaultValue: '' }) : undefined,
        }));

    const set = (key) => (v) => onChange({ ...value, settings: { ...s, [key]: v } });
    const ext = s.output_format === 'png' ? 'png' : 'jpg';
    const base = slugify(value.name) || value.fallbackBase || 'foto';

    const outputSummary = [t(`enums.output_format.${s.output_format}`), `${s.resolution} px`, t(`enums.aspect_ratio.${s.aspect_ratio}`)].join(' · ');
    const watermarkSummary = s.watermark_mode === 'none' || !hasLogo ? t('enums.watermark_mode.none') : `${t(`enums.watermark_mode.${s.watermark_mode}`)} · ${t(`enums.watermark_position.${s.watermark_position}`)}`;

    return (
        <div className="space-y-4">
            <Card>
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

            <Section icon={Sparkles} title={t('batch.settings.optimization')}>
                <Choice label={t('fields.strength')} options={opts('strength', 'optimization_strength', true, STRENGTH_ICONS)} value={s.strength} onChange={set('strength')} describe columns="grid-cols-1 sm:grid-cols-3" />
                <Choice label={t('fields.background')} options={opts('background', 'background_option', false, BACKGROUND_ICONS)} value={s.background} onChange={set('background')} columns="grid-cols-1 sm:grid-cols-2" compact />
                <div className="flex items-start gap-3 rounded-xl bg-stone-50 p-4">
                    <UserX className="mt-0.5 size-5 shrink-0 text-stone-400" aria-hidden />
                    <div className="flex-1">
                        <Toggle label={t('batch.settings.remove_people')} description={t('batch.settings.remove_people_text')} checked={!!s.remove_people} onChange={set('remove_people')} />
                    </div>
                </div>
                <p className="rounded-xl bg-brand-50/60 px-4 py-3 text-sm text-brand-900">{t('batch.settings.integrity')}</p>
            </Section>

            <Section icon={SlidersHorizontal} title={t('batch.settings.output')} summary={outputSummary} collapsible defaultOpen={false}>
                <Choice label={t('fields.output_format')} options={opts('output_format', 'output_format')} value={s.output_format} onChange={set('output_format')} columns="grid-cols-2" compact />
                <Choice label={t('fields.resolution')} options={opts('resolution', 'resolution')} value={s.resolution} onChange={set('resolution')} columns="grid-cols-1 sm:grid-cols-3" compact />
                <Choice
                    label={t('fields.aspect_ratio')}
                    options={opts('aspect_ratio', 'aspect_ratio').map((o) => ({ ...o, visual: <RatioShape ratio={o.value} selected={o.value === s.aspect_ratio} /> }))}
                    value={s.aspect_ratio}
                    onChange={set('aspect_ratio')}
                    columns="grid-cols-2 sm:grid-cols-5"
                    compact
                />
                {s.aspect_ratio !== 'original' && <p className="-mt-3 text-sm text-stone-500">{t('batch.settings.crop_hint')}</p>}
            </Section>

            {/* Always mounted: the header switch opens it while the section is still closed. */}
            <input ref={logoInput} type="file" accept="image/png,image/jpeg" hidden onChange={uploadLogo} />
            <Section
                icon={Stamp}
                title={t('settings.watermark')}
                summary={watermarkSummary}
                collapsible
                open={watermarkOpen}
                onOpenChange={setWatermarkOpen}
                action={<HeaderSwitch checked={watermarkOn} onChange={toggleWatermark} busy={logoBusy} label={t('batch.settings.watermark_switch')} />}
            >
                {logoError && <Alert type="error">{logoError}</Alert>}
                {!hasLogo && (
                    <div className="flex flex-col gap-3 rounded-xl bg-stone-50 p-4 sm:flex-row sm:items-center sm:justify-between">
                        <p className="text-sm text-stone-600">{t('batch.settings.no_logo_inline')}</p>
                        <Button icon={ImageUp} loading={logoBusy} onClick={() => logoInput.current?.click()} className="shrink-0">
                            {t('batch.settings.upload_logo')}
                        </Button>
                    </div>
                )}
                <Choice
                    label={t('fields.watermark_mode')}
                    options={opts('watermark_mode', 'watermark_mode').map((o) => ({ ...o, disabled: !hasLogo && o.value !== 'none' }))}
                    value={s.watermark_mode}
                    onChange={(v) => (hasLogo || v === 'none') && set('watermark_mode')(v)}
                    columns="grid-cols-1 sm:grid-cols-3"
                    compact
                />
                {hasLogo && s.watermark_mode !== 'none' && (
                    <>
                        <Choice label={t('fields.watermark_position')} options={opts('watermark_position', 'watermark_position')} value={s.watermark_position} onChange={set('watermark_position')} columns="grid-cols-2 sm:grid-cols-5" compact />
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
                        <p className="text-xs text-stone-400">{t('download.watermark_note')}</p>
                    </>
                )}
            </Section>
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

import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import api, { errorMessage, fieldErrors } from '../../lib/api';
import { useAuth } from '../../auth/AuthContext';
import { useFetch } from '../../lib/useFetch';
import { Alert, Button, Card, Input, PageHeader, Select, Spinner } from '../../components/ui';
import LogoCard from '../../components/company/LogoCard';

/** Company defaults that pre-fill every new batch. Logo upload follows in phase 7. */
export default function CompanySettings() {
    const { t } = useTranslation();
    const { meta, refresh } = useAuth();
    const { data, loading, error } = useFetch('/company/settings');
    const [form, setForm] = useState(null);
    const [errors, setErrors] = useState({});
    const [status, setStatus] = useState(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (data) setForm(data);
    }, [data]);

    if (loading || !form) {
        return error ? <Alert type="error">{error}</Alert> : <Spinner />;
    }

    const options = (key, group) => (meta?.options?.[key] ?? []).map((value) => ({ value, label: t(`enums.${group}.${value}`) }));
    const bind = (key) => ({
        value: form[key] ?? '',
        error: errors[key],
        onChange: (e) => setForm({ ...form, [key]: e.target.value }),
    });

    const submit = async (e) => {
        e.preventDefault();
        setBusy(true);
        setErrors({});
        setStatus(null);
        try {
            const { data: res } = await api.put('/company/settings', { ...form, default_watermark_opacity: Number(form.default_watermark_opacity) });
            setForm(res.data);
            setStatus({ type: 'success', text: t('common.saved') });
            refresh();
        } catch (err) {
            setErrors(fieldErrors(err));
            setStatus({ type: 'error', text: errorMessage(err) });
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="mx-auto max-w-3xl">
            <PageHeader title={t('settings.title')} description={t('settings.subtitle')} />
            <div className="mb-6">
                <LogoCard logoUrl={form.logo_url} onChange={(data) => setForm({ ...form, has_logo: data.has_logo, logo_url: data.logo_url })} />
            </div>
            <form onSubmit={submit} className="space-y-6">
                {status && <Alert type={status.type}>{status.text}</Alert>}

                <Card>
                    <h2 className="font-semibold">{t('settings.company')}</h2>
                    <div className="mt-5 grid gap-5 sm:grid-cols-2">
                        <Input label={t('fields.company_name')} {...bind('company_name')} />
                        <Input label={t('fields.filename_prefix')} hint={t('settings.prefix_hint', { prefix: form.filename_prefix || 'foto' })} {...bind('filename_prefix')} />
                    </div>
                </Card>

                <Card>
                    <h2 className="font-semibold">{t('settings.defaults')}</h2>
                    <p className="mt-1 text-sm text-stone-500">{t('settings.defaults_text')}</p>
                    <div className="mt-5 grid gap-5 sm:grid-cols-2">
                        <Select label={t('fields.output_format')} options={options('output_format', 'output_format')} {...bind('default_output_format')} />
                        <Select label={t('fields.resolution')} options={options('resolution', 'resolution')} {...bind('default_resolution')} />
                        <Select label={t('fields.aspect_ratio')} options={options('aspect_ratio', 'aspect_ratio')} {...bind('default_aspect_ratio')} />
                        <Select label={t('fields.strength')} options={options('strength', 'optimization_strength')} {...bind('default_strength')} />
                        <div className="sm:col-span-2">
                            <Select label={t('fields.background')} options={options('background', 'background_option')} {...bind('default_background')} />
                        </div>
                    </div>
                </Card>

                <Card>
                    <h2 className="font-semibold">{t('settings.watermark')}</h2>
                    <div className="mt-5 grid gap-5 sm:grid-cols-2">
                        <Select label={t('fields.watermark_mode')} options={options('watermark_mode', 'watermark_mode')} {...bind('default_watermark_mode')} />
                        <Select label={t('fields.watermark_position')} options={options('watermark_position', 'watermark_position')} {...bind('default_watermark_position')} />
                        <div className="sm:col-span-2">
                            <label className="block text-sm font-medium text-stone-700" htmlFor="opacity">
                                {t('fields.watermark_opacity')}: {form.default_watermark_opacity}%
                            </label>
                            <input
                                id="opacity"
                                type="range"
                                min="10"
                                max="100"
                                step="5"
                                className="mt-3 w-full accent-brand-600"
                                value={form.default_watermark_opacity}
                                onChange={(e) => setForm({ ...form, default_watermark_opacity: e.target.value })}
                            />
                        </div>
                    </div>
                </Card>

                <div className="flex justify-end">
                    <Button type="submit" size="lg" loading={busy}>
                        {t('common.save')}
                    </Button>
                </div>
            </form>
        </div>
    );
}

import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import api, { errorMessage, fieldErrors } from '../../lib/api';
import { useFetch } from '../../lib/useFetch';
import { useAuth } from '../../auth/AuthContext';
import MailSettingsCard from '../../components/admin/MailSettingsCard';
import OpenAITest from '../../components/admin/OpenAITest';
import { Choice } from '../../components/batch/BatchSettingsForm';
import { Alert, Button, Card, Input, PageHeader, Spinner, Toggle } from '../../components/ui';

export default function SystemSettings() {
    const { t } = useTranslation();
    const { refresh, reloadMeta } = useAuth();
    const { data, meta, loading, error, setBody } = useFetch('/admin/settings');
    const [form, setForm] = useState(null);
    const [errors, setErrors] = useState({});
    const [status, setStatus] = useState(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (data) setForm(data);
    }, [data]);

    if (loading || !form) return error ? <Alert type="error">{error}</Alert> : <Spinner />;

    const limits = meta.limits;
    const num = (key) => ({
        type: 'number',
        inputMode: 'numeric',
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
            const payload = {
                ...form,
                retention_days: Number(form.retention_days),
                jpg_quality: Number(form.jpg_quality),
                max_images_per_batch: Number(form.max_images_per_batch),
                max_upload_mb: Number(form.max_upload_mb),
                reoptimize_per_image: Number(form.reoptimize_per_image ?? 0),
                reoptimize_per_company_per_day: Number(form.reoptimize_per_company_per_day ?? 0),
                maintenance_message: form.maintenance_message || null,
                // Unchanged default: store nothing, so later improvements of the default apply.
                retouch_prompt: (form.retouch_prompt ?? '').trim() === (meta.default_retouch_prompt ?? '').trim() ? '' : (form.retouch_prompt ?? ''),
            };
            const { data: res } = await api.put('/admin/settings', payload);
            setForm(res.data);
            setStatus({ type: 'success', text: t('common.saved') });
            refresh();
            reloadMeta();
        } catch (err) {
            setErrors(fieldErrors(err));
            setStatus({ type: 'error', text: errorMessage(err) });
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="mx-auto max-w-3xl">
            <PageHeader title={t('admin.settings.title')} description={t('admin.settings.subtitle')} />
            <form onSubmit={submit} className="space-y-6">
                {status && <Alert type={status.type}>{status.text}</Alert>}

                <Card>
                    <h2 className="font-semibold">{t('admin.settings.storage')}</h2>
                    <div className="mt-5 grid gap-5 sm:grid-cols-2">
                        <Input
                            label={t('admin.settings.retention_days')}
                            hint={t('admin.settings.between', { min: limits.retention_days_min, max: limits.retention_days_max })}
                            {...num('retention_days')}
                        />
                    </div>
                </Card>

                <Card>
                    <h2 className="font-semibold">{t('admin.settings.processing')}</h2>
                    <div className="mt-5 grid gap-5 sm:grid-cols-2">
                        <Input
                            label={t('admin.settings.jpg_quality')}
                            hint={t('admin.settings.between', { min: limits.jpg_quality_min, max: limits.jpg_quality_max })}
                            {...num('jpg_quality')}
                        />
                        <Input
                            label={t('admin.settings.max_images')}
                            hint={t('admin.settings.between', { min: 1, max: limits.max_images_per_batch })}
                            {...num('max_images_per_batch')}
                        />
                        <Input
                            label={t('admin.settings.max_upload_mb')}
                            hint={t('admin.settings.max_upload_hint', { max: limits.max_upload_mb_max })}
                            {...num('max_upload_mb')}
                        />
                        <Input label={t('admin.settings.reoptimize_per_image')} hint={t('admin.settings.zero_unlimited')} {...num('reoptimize_per_image')} />
                        <Input label={t('admin.settings.reoptimize_per_day')} hint={t('admin.settings.zero_unlimited')} {...num('reoptimize_per_company_per_day')} />
                    </div>
                    <div className="mt-5 border-t border-stone-100 pt-5">
                        <Toggle
                            label={t('admin.settings.ai_enabled')}
                            description={t('admin.settings.ai_enabled_text')}
                            checked={!!form.ai_enabled}
                            onChange={(v) => setForm({ ...form, ai_enabled: v })}
                        />
                        <div className="mt-5">
                            <Choice
                                label={t('admin.settings.ai_image_quality')}
                                options={['medium', 'high'].map((v) => ({ value: v, label: t(`admin.settings.ai_quality.${v}`), description: t(`admin.settings.ai_quality.${v}_text`) }))}
                                value={form.ai_image_quality ?? 'medium'}
                                onChange={(v) => setForm({ ...form, ai_image_quality: v })}
                                describe
                                columns="grid-cols-1 sm:grid-cols-2"
                            />
                        </div>
                    </div>
                </Card>

                <Card>
                    <h2 className="font-semibold">{t('admin.settings.openai')}</h2>
                    {!meta.openai.configured && (
                        <Alert type="warning" className="mt-4">
                            {t('admin.settings.openai_missing')}
                        </Alert>
                    )}
                    <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                        {[
                            ['openai_key', meta.openai.configured ? t('admin.settings.set') : t('admin.settings.not_set')],
                            ['analysis_model', meta.openai.analysis_model],
                            ['image_model', meta.openai.image_model],
                            ['edit_policy', t(`admin.settings.policy.${meta.openai.edit_policy}`)],
                            ['verify_edits', meta.openai.verify_edits ? t('admin.settings.on') : t('admin.settings.off')],
                        ].map(([key, value]) => (
                            <div key={key}>
                                <dt className="text-stone-500">{t(`admin.settings.${key}`)}</dt>
                                <dd className="font-medium">{value}</dd>
                            </div>
                        ))}
                    </dl>
                    <p className="mt-4 text-xs text-stone-400">{t('admin.settings.openai_env_hint')}</p>
                    {meta.openai.configured && (
                        <OpenAITest
                            lastError={meta.openai.last_error}
                            // After a test the stored "last error" may have changed: fetch it again (the form is left alone).
                            onTested={() => api.get('/admin/settings').then(({ data: res }) => setBody((b) => ({ ...b, meta: res.meta }))).catch(() => null)}
                        />
                    )}
                </Card>

                <Card className="space-y-3">
                    <h2 className="font-semibold">{t('admin.settings.retouch_prompt')}</h2>
                    <p className="text-sm text-stone-500">{t('admin.settings.retouch_prompt_hint')}</p>
                    <textarea
                        rows={14}
                        value={form.retouch_prompt || meta.default_retouch_prompt || ''}
                        onChange={(e) => setForm({ ...form, retouch_prompt: e.target.value })}
                        className="w-full rounded-xl border-0 px-3 py-2.5 text-base ring-1 ring-stone-300 focus:ring-2 focus:ring-brand-500 sm:text-sm"
                    />
                    {errors.retouch_prompt && <p className="text-sm text-red-600">{errors.retouch_prompt}</p>}
                    {form.retouch_prompt && form.retouch_prompt.trim() !== (meta.default_retouch_prompt ?? '').trim() && (
                        <button type="button" onClick={() => setForm({ ...form, retouch_prompt: '' })} className="text-sm font-medium text-brand-700 hover:underline">
                            {t('admin.settings.retouch_prompt_reset')}
                        </button>
                    )}
                </Card>

                <Card className="space-y-5">
                    <h2 className="font-semibold">{t('admin.settings.access')}</h2>
                    <Toggle
                        label={t('admin.settings.registration')}
                        description={t('admin.settings.registration_text')}
                        checked={!!form.registration_enabled}
                        onChange={(v) => setForm({ ...form, registration_enabled: v })}
                    />
                    <Input
                        label={t('admin.settings.maintenance_message')}
                        hint={t('admin.settings.maintenance_hint')}
                        value={form.maintenance_message ?? ''}
                        error={errors.maintenance_message}
                        onChange={(e) => setForm({ ...form, maintenance_message: e.target.value })}
                    />
                </Card>

                <div className="flex justify-end">
                    <Button type="submit" size="lg" loading={busy}>
                        {t('common.save')}
                    </Button>
                </div>
            </form>

            <div className="mt-6">
                <MailSettingsCard />
            </div>
        </div>
    );
}

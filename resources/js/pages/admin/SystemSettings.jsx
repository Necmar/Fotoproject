import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import api, { errorMessage, fieldErrors } from '../../lib/api';
import { useFetch } from '../../lib/useFetch';
import { useAuth } from '../../auth/AuthContext';
import { Alert, Button, Card, Input, PageHeader, Spinner, Toggle } from '../../components/ui';

export default function SystemSettings() {
    const { t } = useTranslation();
    const { refresh } = useAuth();
    const { data, meta, loading, error } = useFetch('/admin/settings');
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
                maintenance_message: form.maintenance_message || null,
            };
            const { data: res } = await api.put('/admin/settings', payload);
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
                    </div>
                    <div className="mt-5 border-t border-stone-100 pt-5">
                        <Toggle
                            label={t('admin.settings.ai_enabled')}
                            description={t('admin.settings.ai_enabled_text')}
                            checked={!!form.ai_enabled}
                            onChange={(v) => setForm({ ...form, ai_enabled: v })}
                        />
                    </div>
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
        </div>
    );
}

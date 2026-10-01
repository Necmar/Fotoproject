/** Small formatting helpers shared by pages. */

export function formatBytes(bytes, locale = 'nl') {
    if (!bytes) return '0 MB';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)));
    const value = bytes / 1024 ** i;

    return `${value.toLocaleString(locale, { maximumFractionDigits: i >= 2 ? 1 : 0 })} ${units[i]}`;
}

export function formatDate(iso, locale = 'nl', withTime = true) {
    if (!iso) return '';
    const options = withTime
        ? { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }
        : { day: 'numeric', month: 'short', year: 'numeric' };

    return new Date(iso).toLocaleString(locale, options);
}

export function formatNumber(value, locale = 'nl') {
    return Number(value ?? 0).toLocaleString(locale);
}

export function formatUsd(value, locale = 'nl') {
    return Number(value ?? 0).toLocaleString(locale, { style: 'currency', currency: 'USD', maximumFractionDigits: 2 });
}

const FINISHED = ['completed', 'completed_with_errors', 'failed'];

function formatTime(iso, locale) {
    return new Date(iso).toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' });
}

function formatDuration(ms, t) {
    const minutes = Math.round(ms / 60000);
    if (minutes < 1) return t('batch.timing.under_minute');
    if (minutes < 60) return t('batch.timing.minutes', { count: minutes });
    return t('batch.timing.hours', { hours: Math.floor(minutes / 60), minutes: minutes % 60 });
}

/** Name of a batch; an unnamed one is called after the moment it was made ("Batch van 1 okt, 20:41"). */
export function batchTitle(batch, t, locale = 'nl') {
    return batch.name || t('batch.unnamed_dated', { date: formatDate(batch.started_at ?? batch.created_at, locale) });
}

/** When a batch was started and finished: "Gestart 1 okt 2026 20:41 · klaar om 20:47 (6 min)". */
export function batchTiming(batch, t, locale = 'nl') {
    if (!batch.started_at) return t('batch.timing.created', { date: formatDate(batch.created_at, locale) });

    const start = formatDate(batch.started_at, locale);
    if (!FINISHED.includes(batch.status) || !batch.completed_at) return t('batch.timing.running', { date: start });

    const sameDay = new Date(batch.started_at).toDateString() === new Date(batch.completed_at).toDateString();
    return t('batch.timing.done', {
        date: start,
        end: sameDay ? formatTime(batch.completed_at, locale) : formatDate(batch.completed_at, locale),
        duration: formatDuration(new Date(batch.completed_at) - new Date(batch.started_at), t),
    });
}

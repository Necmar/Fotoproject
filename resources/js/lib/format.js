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

import { useCallback, useState } from 'react';
import api from './api';
import i18n from './i18n';

/** Translated message for a failed download check. HEAD answers have no body, so the status decides. */
export function downloadErrorMessage(error) {
    const status = error?.response?.status;
    if (!error?.response) return i18n.t('errors.network');
    if (status === 422) return i18n.t('download.errors.nothing');
    if (status === 429) return i18n.t('download.errors.too_many');
    if (status === 403 || status === 404) return i18n.t('download.errors.missing');
    return i18n.t('download.errors.server');
}

/**
 * Checks a download URL first (HEAD, same auth and checks as the real
 * download, a ZIP is built and cached by then) and only then lets the browser
 * download it natively. No blob in memory: safe for large ZIPs and for iOS
 * Safari, where blob downloads are unreliable (home-screen apps, large files).
 */
export async function startDownload(url) {
    await api.head(url, { timeout: 300000 });
    window.location.assign(url);
}

/** Download state for a component: { download(url), busy, error, clearError }. */
export function useDownload() {
    const [busy, setBusy] = useState(null);
    const [error, setError] = useState(null);

    const download = useCallback(async (url) => {
        if (!url) return;
        setBusy(url);
        setError(null);
        try {
            await startDownload(url);
        } catch (err) {
            setError(downloadErrorMessage(err));
        } finally {
            setBusy(null);
        }
    }, []);

    const clearError = useCallback(() => setError(null), []);

    return { download, busy, error, clearError };
}

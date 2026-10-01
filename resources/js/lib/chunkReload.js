/**
 * After a deploy, old chunk files are gone: a lazy page or preload then fails
 * to load. One reload fetches the new index and fixes it. A timestamp (not a
 * flag) guards against reload loops yet still works for later deploys in the
 * same browser session.
 */
const KEY = 'bora:reloaded-after-deploy';
const WINDOW_MS = 10_000;

const CHUNK_ERROR = /Failed to fetch dynamically imported module|Importing a module script failed|error loading dynamically imported module|Unable to preload CSS|Loading chunk .* failed/i;

export function isChunkLoadError(error) {
    return CHUNK_ERROR.test(String(error?.message ?? error ?? ''));
}

/** Reloads the page unless that just happened. Returns true when a reload was started. */
export function reloadForNewVersion() {
    try {
        const last = Number(sessionStorage.getItem(KEY) ?? 0);
        if (Date.now() - last < WINDOW_MS) return false;
        sessionStorage.setItem(KEY, String(Date.now()));
    } catch {
        return false;
    }
    window.location.reload();
    return true;
}

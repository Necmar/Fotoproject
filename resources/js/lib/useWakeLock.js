import { useEffect } from 'react';

/**
 * Keeps the phone screen on while `active` (e.g. during uploads): a locked
 * iPhone/Android pauses the page and its uploads. Silently does nothing where
 * the Screen Wake Lock API is missing (older iOS) or refused.
 */
export function useWakeLock(active) {
    useEffect(() => {
        if (!active || !('wakeLock' in navigator)) return undefined;

        let lock = null;
        let released = false;

        const acquire = async () => {
            try {
                lock = await navigator.wakeLock.request('screen');
                if (released) lock.release().catch(() => {});
            } catch {
                lock = null;
            }
        };
        // The lock is dropped whenever the page is hidden; take it again on return.
        const onVisible = () => document.visibilityState === 'visible' && !released && acquire();

        acquire();
        document.addEventListener('visibilitychange', onVisible);

        return () => {
            released = true;
            document.removeEventListener('visibilitychange', onVisible);
            lock?.release().catch(() => {});
        };
    }, [active]);
}

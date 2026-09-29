import { useEffect, useRef } from 'react';

/**
 * Calls `callback` every `interval` ms while `enabled` is true. In a hidden
 * tab it keeps going at a slower pace (so the tab title and a completion
 * notification stay current), and it refreshes at once when the tab comes back.
 * No WebSockets needed; works on shared hosting.
 */
export function usePolling(callback, interval, enabled, hiddenInterval = interval * 5) {
    const saved = useRef(callback);

    useEffect(() => {
        saved.current = callback;
    }, [callback]);

    useEffect(() => {
        if (!enabled) return undefined;

        let timer = null;
        let stopped = false;

        const next = () => {
            if (!stopped) timer = setTimeout(tick, document.visibilityState === 'visible' ? interval : hiddenInterval);
        };

        const tick = async () => {
            try {
                await saved.current();
            } catch {
                // A failed poll is retried on the next tick.
            }
            next();
        };

        const onVisible = () => {
            if (document.visibilityState === 'visible') {
                clearTimeout(timer);
                tick();
            }
        };

        next();
        document.addEventListener('visibilitychange', onVisible);
        window.addEventListener('focus', onVisible);

        return () => {
            stopped = true;
            clearTimeout(timer);
            document.removeEventListener('visibilitychange', onVisible);
            window.removeEventListener('focus', onVisible);
        };
    }, [interval, hiddenInterval, enabled]);
}

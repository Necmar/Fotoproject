import { useEffect, useRef } from 'react';

/**
 * Calls `callback` every `interval` ms while `enabled` is true. Pauses while
 * the tab is hidden and fires once immediately when it becomes visible again.
 * No WebSockets needed; works on shared hosting.
 */
export function usePolling(callback, interval, enabled) {
    const saved = useRef(callback);

    useEffect(() => {
        saved.current = callback;
    }, [callback]);

    useEffect(() => {
        if (!enabled) return undefined;

        let timer = null;
        let stopped = false;

        const tick = async () => {
            if (stopped) return;
            if (document.visibilityState === 'visible') {
                try {
                    await saved.current();
                } catch {
                    // A failed poll is retried on the next tick.
                }
            }
            if (!stopped) timer = setTimeout(tick, interval);
        };

        const onVisible = () => {
            if (document.visibilityState === 'visible') {
                clearTimeout(timer);
                tick();
            }
        };

        timer = setTimeout(tick, interval);
        document.addEventListener('visibilitychange', onVisible);

        return () => {
            stopped = true;
            clearTimeout(timer);
            document.removeEventListener('visibilitychange', onVisible);
        };
    }, [interval, enabled]);
}

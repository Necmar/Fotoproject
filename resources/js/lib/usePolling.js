import { useEffect, useRef } from 'react';

/**
 * Calls `callback` every `interval` ms while `enabled` is true. In a hidden
 * tab it keeps going at a slower pace (so the tab title and a completion
 * notification stay current), and it refreshes at once when the tab comes back.
 * Only one request runs at a time and only one timer chain exists.
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
        let inFlight = false;
        // Every (re)schedule gets a new generation; a stale chain stops itself.
        let generation = 0;

        const schedule = () => {
            clearTimeout(timer);
            const mine = ++generation;
            if (stopped) return;
            timer = setTimeout(() => tick(mine), document.visibilityState === 'visible' ? interval : hiddenInterval);
        };

        const tick = async (mine) => {
            if (stopped || mine !== generation || inFlight) return;
            inFlight = true;
            try {
                await saved.current();
            } catch {
                // A failed poll is retried on the next tick.
            } finally {
                inFlight = false;
            }
            if (mine === generation) schedule();
        };

        // visibilitychange covers tab switches and minimising; focus alone would also fire on
        // every click back into the window, so it is not used.
        const onVisibility = () => {
            if (document.visibilityState !== 'visible') return;
            if (inFlight) return; // the running request reschedules when it finishes
            clearTimeout(timer);
            tick(++generation);
        };

        schedule();
        document.addEventListener('visibilitychange', onVisibility);

        return () => {
            stopped = true;
            generation += 1;
            clearTimeout(timer);
            document.removeEventListener('visibilitychange', onVisibility);
        };
    }, [interval, hiddenInterval, enabled]);
}

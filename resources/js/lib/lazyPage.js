import { lazy } from 'react';
import { isChunkLoadError, reloadForNewVersion } from './chunkReload';

const never = () => new Promise(() => {});

/**
 * React.lazy for route pages. When the chunk is missing after a deploy the
 * page reloads once (also when Vite's preload handler in main.jsx already
 * started that reload); otherwise the error reaches the route's <Crash />.
 */
export function lazyPage(factory) {
    return lazy(async () => {
        try {
            const module = await factory();
            // main.jsx cancelled a preload error and is reloading: wait for it.
            if (!module?.default) return never();
            return module;
        } catch (error) {
            if (isChunkLoadError(error) && reloadForNewVersion()) return never();
            throw error;
        }
    });
}

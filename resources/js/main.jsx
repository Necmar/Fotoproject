import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { i18nReady } from './lib/i18n';
import { reloadForNewVersion } from './lib/chunkReload';
import App from './App';

// A lazy chunk or its CSS from the previous deploy is gone: reload once (see chunkReload.js).
window.addEventListener('vite:preloadError', (event) => {
    if (reloadForNewVersion()) event.preventDefault();
});

// Waits for the active language only when it is not bundled (English), so no untranslated keys flash.
i18nReady.finally(() => {
    createRoot(document.getElementById('app')).render(
        <StrictMode>
            <App />
        </StrictMode>,
    );
});

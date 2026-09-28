import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import './lib/i18n';
import App from './App';

window.addEventListener('vite:preloadError', (event) => {
    const key = 'bora:reloaded-after-deploy';
    try {
        if (sessionStorage.getItem(key)) return;
        sessionStorage.setItem(key, '1');
    } catch {
        return;
    }
    event.preventDefault();
    window.location.reload();
});

createRoot(document.getElementById('app')).render(
    <StrictMode>
        <App />
    </StrictMode>,
);

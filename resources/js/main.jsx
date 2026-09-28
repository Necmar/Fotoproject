import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import './lib/i18n';
import App from './App';

createRoot(document.getElementById('app')).render(
    <StrictMode>
        <App />
    </StrictMode>,
);

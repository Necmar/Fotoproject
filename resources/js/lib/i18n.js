import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import nl from '../locales/nl.json';
import en from '../locales/en.json';

export const SUPPORTED_LOCALES = ['nl', 'en'];

function initialLocale() {
    const stored = safeStorage('get', 'locale');
    const server = document.getElementById('app')?.dataset.locale;

    return [stored, server, 'nl'].find((l) => SUPPORTED_LOCALES.includes(l));
}

/** localStorage can throw in private mode; never let that break the app. */
export function safeStorage(action, key, value) {
    try {
        if (action === 'get') return window.localStorage.getItem(key);
        window.localStorage.setItem(key, value);
    } catch {
        return null;
    }
    return null;
}

i18n.use(initReactI18next).init({
    resources: { nl: { translation: nl }, en: { translation: en } },
    lng: initialLocale(),
    fallbackLng: 'nl',
    // Keys contain ':' (aspect ratios like 1:1), so disable namespace splitting.
    nsSeparator: false,
    interpolation: { escapeValue: false },
});

i18n.on('languageChanged', (lng) => {
    document.documentElement.lang = lng;
    safeStorage('set', 'locale', lng);
});

export default i18n;

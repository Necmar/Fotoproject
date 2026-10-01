import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import nl from '../locales/nl.json';

export const SUPPORTED_LOCALES = ['nl', 'en'];

// Dutch (default and fallback) is bundled; other languages load on demand.
const loaders = {
    en: () => import('../locales/en.json'),
};

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

/** Loads a language's texts (once). Resolves false when that failed. */
async function ensureLoaded(lng) {
    if (i18n.hasResourceBundle(lng, 'translation') || !loaders[lng]) return true;
    try {
        const module = await loaders[lng]();
        i18n.addResourceBundle(lng, 'translation', module.default ?? module, true, true);
        return true;
    } catch {
        return false;
    }
}

/** Switch language without showing untranslated keys: the texts load first. */
export async function changeLocale(lng) {
    if (!SUPPORTED_LOCALES.includes(lng) || lng === i18n.language) return;
    if (await ensureLoaded(lng)) await i18n.changeLanguage(lng);
}

const start = initialLocale();

i18n.use(initReactI18next).init({
    resources: { nl: { translation: nl } },
    partialBundledLanguages: true,
    lng: 'nl',
    fallbackLng: 'nl',
    // Keys contain ':' (aspect ratios like 1:1), so disable namespace splitting.
    nsSeparator: false,
    interpolation: { escapeValue: false },
});

i18n.on('languageChanged', (lng) => {
    document.documentElement.lang = lng;
    safeStorage('set', 'locale', lng);
});

/** Resolves once the starting language is ready (main.jsx renders after it). */
export const i18nReady = start === 'nl' ? Promise.resolve() : changeLocale(start);

export default i18n;

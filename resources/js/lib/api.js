import axios from 'axios';
import i18n from './i18n';

/**
 * Axios instance for the Laravel JSON API.
 * Auth is a same-domain session cookie; Laravel sets the XSRF-TOKEN cookie on
 * every response and axios sends it back as the X-XSRF-TOKEN header.
 */
const api = axios.create({
    baseURL: '/api',
    withCredentials: true,
    withXSRFToken: true,
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
});

api.interceptors.request.use((config) => {
    config.headers['X-Locale'] = i18n.language;
    return config;
});

/**
 * A 2xx answer that is not JSON (e.g. the SPA's HTML after a redirect, or a
 * hosting error page) means the API was not reached: treat it as an error.
 * HEAD and blob/arraybuffer requests (downloads) are exempt.
 */
function looksLikeJson(response) {
    if (response.status === 204 || response.config?.method === 'head' || ['blob', 'arraybuffer', 'stream'].includes(response.config?.responseType)) return true;
    const type = String(response.headers?.['content-type'] ?? '');
    if (type && !type.includes('json')) return false;
    return response.data === '' || (response.data !== null && typeof response.data === 'object');
}

api.interceptors.response.use(
    (response) => {
        if (looksLikeJson(response)) return response;
        const error = new Error('Unexpected non-JSON response');
        error.code = 'ERR_BAD_RESPONSE';
        error.config = response.config;
        error.response = { ...response, data: { message: i18n.t('errors.unexpected_response'), code: 'unexpected_response' } };
        return Promise.reject(error);
    },
    (error) => {
        const status = error.response?.status;
        const code = error.response?.data?.code;

        if (status === 401 || status === 419 || code === 'account_blocked') {
            window.dispatchEvent(new CustomEvent('auth:signed-out', { detail: { code: code ?? (status === 419 ? 'session_expired' : 'unauthenticated') } }));
        }

        return Promise.reject(error);
    },
);

/** Human-readable message for any API error. */
export function errorMessage(error) {
    if (!error?.response) {
        return i18n.t('errors.network');
    }

    return error.response.data?.message || i18n.t('errors.generic');
}

/** Field errors from a 422 response as { field: 'first message' }. */
export function fieldErrors(error) {
    const errors = error?.response?.status === 422 ? error.response.data?.errors ?? {} : {};

    return Object.fromEntries(Object.entries(errors).map(([key, messages]) => [key, messages[0]]));
}

export default api;

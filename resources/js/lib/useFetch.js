import { useCallback, useEffect, useRef, useState } from 'react';
import api, { errorMessage } from './api';

/**
 * GET an API resource and keep it in state. Returns the full JSON body
 * ({ data, meta, links }) so paginated lists work too.
 *
 * reload() shows the loading state; refetch() (for polling) updates silently:
 * no loading flag, and the previous data stays when it fails. A failed refetch
 * is reported via `refetchError` (with its HTTP status) and rethrown.
 */
export function useFetch(url, params) {
    const [body, setBody] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [refetchError, setRefetchError] = useState(null);
    const key = JSON.stringify(params ?? {});
    const latest = useRef(0);

    const load = useCallback(async () => {
        const request = ++latest.current;
        setLoading(true);
        setError(null);
        setRefetchError(null);
        try {
            const { data } = await api.get(url, { params: JSON.parse(key) });
            if (request === latest.current) setBody(data);
        } catch (err) {
            if (request === latest.current) setError(errorMessage(err));
        } finally {
            if (request === latest.current) setLoading(false);
        }
    }, [url, key]);

    const refetch = useCallback(async () => {
        const request = ++latest.current;
        try {
            const { data } = await api.get(url, { params: JSON.parse(key) });
            if (request === latest.current) {
                setBody(data);
                setRefetchError(null);
            }
        } catch (err) {
            if (request === latest.current) setRefetchError({ status: err.response?.status ?? null, message: errorMessage(err) });
            throw err;
        }
    }, [url, key]);

    useEffect(() => {
        load();
    }, [load]);

    return { body, data: body?.data, meta: body?.meta, loading, error, reload: load, refetch, refetchError, setBody };
}

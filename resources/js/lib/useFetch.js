import { useCallback, useEffect, useRef, useState } from 'react';
import api, { errorMessage } from './api';

/**
 * GET an API resource and keep it in state. Returns the full JSON body
 * ({ data, meta, links }) so paginated lists work too.
 */
export function useFetch(url, params) {
    const [body, setBody] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const key = JSON.stringify(params ?? {});
    const latest = useRef(0);

    const load = useCallback(async () => {
        const request = ++latest.current;
        setLoading(true);
        setError(null);
        try {
            const { data } = await api.get(url, { params: JSON.parse(key) });
            if (request === latest.current) setBody(data);
        } catch (err) {
            if (request === latest.current) setError(errorMessage(err));
        } finally {
            if (request === latest.current) setLoading(false);
        }
    }, [url, key]);

    useEffect(() => {
        load();
    }, [load]);

    return { body, data: body?.data, meta: body?.meta, loading, error, reload: load, setBody };
}

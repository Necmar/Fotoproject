import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import api from '../lib/api';
import i18n from '../lib/i18n';

const AuthContext = createContext(null);

/**
 * Holds the signed-in user and the public app meta (/api/meta).
 * user === undefined while loading, null when signed out.
 */
export function AuthProvider({ children }) {
    const [user, setUser] = useState(undefined);
    const [meta, setMeta] = useState(null);
    const [signedOutReason, setSignedOutReason] = useState(null);

    const applyUser = useCallback((next) => {
        setUser(next);
        if (next?.locale && next.locale !== i18n.language) {
            i18n.changeLanguage(next.locale);
        }
    }, []);

    const refresh = useCallback(async () => {
        try {
            const { data } = await api.get('/auth/me');
            applyUser(data.data);
            return data.data;
        } catch {
            setUser(null);
            return null;
        }
    }, [applyUser]);

    useEffect(() => {
        // /meta first: it also sets the XSRF-TOKEN cookie for later POSTs.
        api.get('/meta')
            .then(({ data }) => setMeta(data.data))
            .catch(() => setMeta({}))
            .finally(refresh);
    }, [refresh]);

    useEffect(() => {
        const onSignedOut = (event) => {
            setSignedOutReason(event.detail?.code ?? null);
            setUser(null);
            // After an expired session the old XSRF cookie is useless: fetch a fresh one for the next login.
            if (event.detail?.code === 'session_expired') api.get('/meta').catch(() => {});
        };
        window.addEventListener('auth:signed-out', onSignedOut);
        return () => window.removeEventListener('auth:signed-out', onSignedOut);
    }, []);

    const login = useCallback(
        async (credentials) => {
            const { data } = await api.post('/auth/login', credentials);
            setSignedOutReason(null);
            applyUser(data.data);
            return data.data;
        },
        [applyUser],
    );

    const logout = useCallback(async () => {
        try {
            await api.post('/auth/logout');
        } finally {
            setUser(null);
        }
    }, []);

    const value = useMemo(
        () => ({ user, setUser: applyUser, meta, login, logout, refresh, signedOutReason }),
        [user, applyUser, meta, login, logout, refresh, signedOutReason],
    );

    return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
    const context = useContext(AuthContext);
    if (!context) throw new Error('useAuth must be used inside <AuthProvider>');
    return context;
}

/** Where a signed-in user belongs by default. */
export function homePathFor(user) {
    if (!user) return '/login';
    if (user.role === 'super_admin') return '/admin';
    return '/';
}

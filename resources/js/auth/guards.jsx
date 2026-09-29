import { Navigate, Outlet, useLocation } from 'react-router';
import { homePathFor, useAuth } from './AuthContext';
import { FullPageSpinner } from '../components/ui';

/** Route guards. The server enforces everything again; these only steer the UI. */

export function RequireGuest() {
    const { user } = useAuth();
    if (user === undefined) return <FullPageSpinner />;
    if (user) return <Navigate to={homePathFor(user)} replace />;
    return <Outlet />;
}

export function RequireRole({ role }) {
    const { user } = useAuth();
    const location = useLocation();

    if (user === undefined) return <FullPageSpinner />;
    if (!user) return <Navigate to="/login" replace state={{ from: location.pathname }} />;
    if (user.role !== role) return <Navigate to={homePathFor(user)} replace />;

    return <Outlet />;
}

/** Company area requires a verified e-mail address. */
export function RequireVerified() {
    const { user } = useAuth();
    if (!user.email_verified) return <Navigate to="/verify-email" replace />;
    return <Outlet />;
}

export function RequireAuth() {
    const { user } = useAuth();
    if (user === undefined) return <FullPageSpinner />;
    if (!user) return <Navigate to="/login" replace />;
    return <Outlet />;
}

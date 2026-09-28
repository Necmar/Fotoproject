import { createBrowserRouter, Navigate, RouterProvider } from 'react-router';
import { useTranslation } from 'react-i18next';
import { AuthProvider } from './auth/AuthContext';
import { RequireAuth, RequireGuest, RequireRole, RequireVerified } from './auth/guards';
import AuthLayout from './layouts/AuthLayout';
import AppLayout from './layouts/AppLayout';
import Login from './pages/auth/Login';
import ForgotPassword from './pages/auth/ForgotPassword';
import ResetPassword from './pages/auth/ResetPassword';
import VerifyEmail from './pages/auth/VerifyEmail';
import Register from './pages/auth/Register';
import Account from './pages/account/Account';
import Dashboard from './pages/company/Dashboard';
import CompanySettings from './pages/company/Settings';
import AdminDashboard from './pages/admin/AdminDashboard';
import Companies from './pages/admin/Companies';
import CompanyCreate from './pages/admin/CompanyCreate';
import CompanyDetail from './pages/admin/CompanyDetail';
import AdminBatches from './pages/admin/Batches';
import SystemSettings from './pages/admin/SystemSettings';
import ActivityLog from './pages/admin/ActivityLog';
import NotFound from './pages/NotFound';
import { useAuth } from './auth/AuthContext';

function CompanyShell() {
    const { t } = useTranslation();
    return (
        <AppLayout
            nav={[
                { to: '/', label: t('nav.dashboard'), end: true },
                { to: '/settings', label: t('nav.settings') },
            ]}
        />
    );
}

function AdminShell() {
    const { t } = useTranslation();
    return (
        <AppLayout
            badge="Super Admin"
            nav={[
                { to: '/admin', label: t('nav.overview'), end: true },
                { to: '/admin/companies', label: t('nav.companies') },
                { to: '/admin/batches', label: t('nav.batches') },
                { to: '/admin/activity', label: t('nav.activity') },
                { to: '/admin/settings', label: t('nav.system') },
            ]}
        />
    );
}

/** /account lives in whichever shell fits the signed-in role. */
function AccountShell() {
    const { user } = useAuth();
    return user.role === 'super_admin' ? <AdminShell /> : <CompanyShell />;
}

const router = createBrowserRouter([
    {
        element: <RequireGuest />,
        children: [
            {
                element: <AuthLayout />,
                children: [
                    { path: '/login', element: <Login /> },
                    { path: '/forgot-password', element: <ForgotPassword /> },
                    { path: '/register', element: <Register /> },
                ],
            },
        ],
    },
    // Reachable signed in or out (links from e-mails).
    {
        element: <AuthLayout />,
        children: [
            { path: '/reset-password/:token', element: <ResetPassword /> },
            { path: '/welcome/:token', element: <ResetPassword invite /> },
        ],
    },
    {
        element: <RequireAuth />,
        children: [
            { element: <AuthLayout />, children: [{ path: '/verify-email', element: <VerifyEmail /> }] },
            { element: <AccountShell />, children: [{ path: '/account', element: <Account /> }] },
        ],
    },
    {
        element: <RequireRole role="company_owner" />,
        children: [
            {
                element: <RequireVerified />,
                children: [
                    {
                        element: <CompanyShell />,
                        children: [
                            { path: '/', element: <Dashboard /> },
                            { path: '/settings', element: <CompanySettings /> },
                        ],
                    },
                ],
            },
        ],
    },
    {
        path: '/admin',
        element: <RequireRole role="super_admin" />,
        children: [
            {
                element: <AdminShell />,
                children: [
                    { index: true, element: <AdminDashboard /> },
                    { path: 'companies', element: <Companies /> },
                    { path: 'companies/new', element: <CompanyCreate /> },
                    { path: 'companies/:id', element: <CompanyDetail /> },
                    { path: 'batches', element: <AdminBatches /> },
                    { path: 'settings', element: <SystemSettings /> },
                    { path: 'activity', element: <ActivityLog /> },
                ],
            },
        ],
    },
    { path: '/home', element: <Navigate to="/" replace /> },
    { path: '*', element: <NotFound /> },
]);

export default function App() {
    return (
        <AuthProvider>
            <RouterProvider router={router} />
        </AuthProvider>
    );
}

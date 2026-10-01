import { createBrowserRouter, Navigate, RouterProvider } from "react-router";
import { useTranslation } from "react-i18next";
import { AuthProvider } from "./auth/AuthContext";
import {
    RequireAuth,
    RequireGuest,
    RequireRole,
    RequireVerified,
} from "./auth/guards";
import AuthLayout from "./layouts/AuthLayout";
import AppLayout from "./layouts/AppLayout";
import Login from "./pages/auth/Login";
import Dashboard from "./pages/company/Dashboard";
import BatchNew from "./pages/company/BatchNew";
import { lazyPage } from "./lib/lazyPage";

// Pages that are not the first screen load on demand (smaller first download).
const ForgotPassword = lazyPage(() => import("./pages/auth/ForgotPassword"));
const ResetPassword = lazyPage(() => import("./pages/auth/ResetPassword"));
const VerifyEmail = lazyPage(() => import("./pages/auth/VerifyEmail"));
const Register = lazyPage(() => import("./pages/auth/Register"));
const Account = lazyPage(() => import("./pages/account/Account"));
const CompanySettings = lazyPage(() => import("./pages/company/Settings"));
const BatchEdit = lazyPage(() => import("./pages/company/BatchEdit"));
const BatchDetail = lazyPage(() => import("./pages/company/BatchDetail"));
const History = lazyPage(() => import("./pages/company/History"));
const AdminDashboard = lazyPage(() => import("./pages/admin/AdminDashboard"));
const Companies = lazyPage(() => import("./pages/admin/Companies"));
const CompanyCreate = lazyPage(() => import("./pages/admin/CompanyCreate"));
const CompanyDetail = lazyPage(() => import("./pages/admin/CompanyDetail"));
const AdminBatches = lazyPage(() => import("./pages/admin/Batches"));
const SystemSettings = lazyPage(() => import("./pages/admin/SystemSettings"));
const ActivityLog = lazyPage(() => import("./pages/admin/ActivityLog"));
import Crash from "./pages/Crash";
import NotFound from "./pages/NotFound";
import { useAuth } from "./auth/AuthContext";

function CompanyShell() {
    const { t } = useTranslation();
    return (
        <AppLayout
            nav={[
                { to: "/", label: t("nav.dashboard"), end: true },
                { to: "/history", label: t("nav.history") },
                { to: "/settings", label: t("nav.settings") },
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
                { to: "/admin", label: t("nav.overview"), end: true },
                { to: "/admin/companies", label: t("nav.companies") },
                { to: "/admin/batches", label: t("nav.batches") },
                { to: "/admin/activity", label: t("nav.activity") },
                { to: "/admin/settings", label: t("nav.system") },
            ]}
        />
    );
}

/** /account lives in whichever shell fits the signed-in role. */
function AccountShell() {
    const { user } = useAuth();
    return user.role === "super_admin" ? <AdminShell /> : <CompanyShell />;
}

const router = createBrowserRouter([
    {
        errorElement: <Crash />,
        children: [
            {
                element: <RequireGuest />,
                children: [
                    {
                        element: <AuthLayout />,
                        children: [
                            { path: "/login", element: <Login /> },
                            {
                                path: "/forgot-password",
                                element: <ForgotPassword />,
                            },
                            { path: "/register", element: <Register /> },
                        ],
                    },
                ],
            },
            // Reachable signed in or out (links from e-mails).
            {
                element: <AuthLayout />,
                children: [
                    {
                        path: "/reset-password/:token",
                        element: <ResetPassword />,
                    },
                    {
                        path: "/welcome/:token",
                        element: <ResetPassword invite />,
                    },
                ],
            },
            {
                element: <RequireAuth />,
                children: [
                    {
                        element: <AuthLayout />,
                        children: [
                            { path: "/verify-email", element: <VerifyEmail /> },
                        ],
                    },
                    {
                        element: <AccountShell />,
                        children: [{ path: "/account", element: <Account /> }],
                    },
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
                                    { path: "/", element: <Dashboard /> },
                                    {
                                        path: "/settings",
                                        element: <CompanySettings />,
                                    },
                                    { path: "/history", element: <History /> },
                                    {
                                        path: "/batches/new",
                                        element: <BatchNew />,
                                    },
                                    {
                                        path: "/batches/:id/edit",
                                        element: <BatchEdit />,
                                    },
                                    {
                                        path: "/batches/:id",
                                        element: <BatchDetail />,
                                    },
                                ],
                            },
                        ],
                    },
                ],
            },
            {
                path: "/admin",
                element: <RequireRole role="super_admin" />,
                children: [
                    {
                        element: <AdminShell />,
                        children: [
                            { index: true, element: <AdminDashboard /> },
                            { path: "companies", element: <Companies /> },
                            {
                                path: "companies/new",
                                element: <CompanyCreate />,
                            },
                            {
                                path: "companies/:id",
                                element: <CompanyDetail />,
                            },
                            { path: "batches", element: <AdminBatches /> },
                            { path: "settings", element: <SystemSettings /> },
                            { path: "activity", element: <ActivityLog /> },
                        ],
                    },
                ],
            },
            { path: "/home", element: <Navigate to="/" replace /> },
            { path: "*", element: <NotFound /> },
        ],
    },
]);

export default function App() {
    return (
        <AuthProvider>
            <RouterProvider router={router} />
        </AuthProvider>
    );
}

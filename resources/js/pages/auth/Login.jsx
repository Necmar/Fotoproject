import { useState } from "react";
import { Link, useLocation, useNavigate, useSearchParams } from "react-router";
import { useTranslation } from "react-i18next";
import { homePathFor, useAuth } from "../../auth/AuthContext";
import { errorMessage, fieldErrors } from "../../lib/api";
import { Alert, Button, Input } from "../../components/ui";
import AlreadySignedIn, { isAlreadyAuthenticated } from "../../components/AlreadySignedIn";

export default function Login() {
    const { t } = useTranslation();
    const { login, signedOutReason, meta } = useAuth();
    const navigate = useNavigate();
    const location = useLocation();
    const [params] = useSearchParams();
    const [form, setForm] = useState({
        email: "",
        password: "",
        remember: true,
    });
    const [errors, setErrors] = useState({});
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);
    const [signedIn, setSignedIn] = useState(null);

    const verified = params.get("verified");

    const submit = async (e) => {
        e.preventDefault();
        setBusy(true);
        setErrors({});
        setError(null);
        setSignedIn(null);
        try {
            const user = await login(form);
            const from = location.state?.from;
            navigate(
                from && user.role !== "super_admin" ? from : homePathFor(user),
                { replace: true },
            );
        } catch (err) {
            if (isAlreadyAuthenticated(err)) {
                setSignedIn(errorMessage(err));
                return;
            }
            const fields = fieldErrors(err);
            setErrors(fields);
            if (!Object.keys(fields).length) setError(errorMessage(err));
        } finally {
            setBusy(false);
        }
    };

    return (
        <div>
            <h1 className="text-2xl font-semibold tracking-tight">
                {t("auth.login.title")}
            </h1>
            <p className="mt-1 text-stone-500">{t("auth.login.subtitle")}</p>

            <div className="mt-6 space-y-3">
                {verified === "1" && (
                    <Alert type="success">{t("auth.verify.done")}</Alert>
                )}
                {verified === "invalid" && (
                    <Alert type="error">{t("auth.verify.invalid")}</Alert>
                )}
                {signedOutReason === "account_blocked" && (
                    <Alert type="error">{t("auth.blocked")}</Alert>
                )}
                {signedOutReason === "session_expired" && (
                    <Alert type="info">{t("auth.session_expired")}</Alert>
                )}
                <Alert type="error">{error}</Alert>
                {signedIn && <AlreadySignedIn message={signedIn} onSignedOut={() => setSignedIn(null)} />}
            </div>

            <form onSubmit={submit} className="mt-6 space-y-5" noValidate>
                <Input
                    label={t("fields.email")}
                    type="email"
                    autoComplete="username"
                    inputMode="email"
                    required
                    value={form.email}
                    error={errors.email}
                    onChange={(e) =>
                        setForm({ ...form, email: e.target.value })
                    }
                />
                <Input
                    label={t("fields.password")}
                    type="password"
                    autoComplete="current-password"
                    required
                    value={form.password}
                    error={errors.password}
                    onChange={(e) =>
                        setForm({ ...form, password: e.target.value })
                    }
                />
                <div className="flex items-center justify-between text-sm">
                    <label className="flex items-center gap-2 text-stone-600">
                        <input
                            type="checkbox"
                            className="size-4 rounded border-stone-300 text-brand-600"
                            checked={form.remember}
                            onChange={(e) =>
                                setForm({ ...form, remember: e.target.checked })
                            }
                        />
                        {t("auth.login.remember")}
                    </label>
                    {meta?.mail_enabled !== false && (
                        <Link
                            to="/forgot-password"
                            className="font-medium text-brand-700 hover:text-brand-800"
                        >
                            {t("auth.login.forgot")}
                        </Link>
                    )}
                </div>
                <Button
                    type="submit"
                    size="lg"
                    className="w-full"
                    loading={busy}
                >
                    {t("auth.login.submit")}
                </Button>
            </form>

            {meta?.registration_enabled && (
                <p className="mt-6 text-center text-sm text-stone-500">
                    {t("auth.login.no_account")}{" "}
                    <Link to="/register" className="font-medium text-brand-700">
                        {t("auth.register.link")}
                    </Link>
                </p>
            )}
        </div>
    );
}

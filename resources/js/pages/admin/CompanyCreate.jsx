import { useState } from "react";
import { useNavigate } from "react-router";
import { useTranslation } from "react-i18next";
import { useAuth } from "../../auth/AuthContext";
import api, { errorMessage, fieldErrors } from "../../lib/api";
import {
    Alert,
    Button,
    Card,
    Input,
    PageHeader,
    Select,
    Toggle,
} from "../../components/ui";

export default function CompanyCreate() {
    const { t } = useTranslation();
    const navigate = useNavigate();
    const [form, setForm] = useState({
        company_name: "",
        owner_name: "",
        email: "",
        password: "",
        locale: "nl",
        mark_verified: false,
    });
    const [setPassword, setSetPassword] = useState(false);
    // Without e-mail there are no invitations: the Super Admin always sets the password.
    const mailOn = useAuth().meta?.mail_enabled !== false;
    const [errors, setErrors] = useState({});
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    const set = (key) => (e) => setForm({ ...form, [key]: e.target.value });

    const submit = async (e) => {
        e.preventDefault();
        setErrors({});
        setError(null);
        const withPassword = setPassword || !mailOn;
        // The toggle is on but the field is empty: say so, never fall back to an invitation silently.
        if (withPassword && !form.password) {
            setErrors({ password: t("admin.companies.password_required") });
            return;
        }
        setBusy(true);
        try {
            const { mark_verified, ...rest } = form;
            const payload = withPassword
                ? { ...rest, mark_verified }
                : { ...rest, password: null };
            const { data } = await api.post("/admin/companies", payload);
            navigate(`/admin/companies/${data.data.id}`, {
                state: {
                    created: true,
                    invited: !withPassword,
                    warning: data.warning ?? null,
                },
            });
        } catch (err) {
            const fields = fieldErrors(err);
            setErrors(fields);
            // A 422 for a field this form does not show still needs a visible message.
            const shown = ["company_name", "owner_name", "email", "password", "locale"];
            if (
                err.response?.status !== 422 ||
                !Object.keys(fields).some((k) => shown.includes(k))
            ) {
                setError(
                    err.response?.status === 422 &&
                        !err.response?.data?.message
                        ? t("admin.companies.check_fields")
                        : errorMessage(err),
                );
            }
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="mx-auto max-w-2xl">
            <PageHeader
                title={t("admin.companies.new")}
                description={t("admin.companies.new_text")}
            />
            <form onSubmit={submit}>
                <Card className="space-y-5">
                    <Alert type="error">{error}</Alert>
                    <Input
                        label={t("fields.company_name")}
                        value={form.company_name}
                        error={errors.company_name}
                        onChange={set("company_name")}
                    />
                    <Input
                        label={t("fields.owner_name")}
                        value={form.owner_name}
                        error={errors.owner_name}
                        onChange={set("owner_name")}
                    />
                    <Input
                        label={t("fields.email")}
                        type="email"
                        value={form.email}
                        error={errors.email}
                        onChange={set("email")}
                    />
                    <Select
                        label={t("common.language")}
                        value={form.locale}
                        options={[
                            { value: "nl", label: "Nederlands" },
                            { value: "en", label: "English" },
                        ]}
                        onChange={set("locale")}
                    />
                    <div className="space-y-4 rounded-xl bg-stone-50 p-4">
                        {!mailOn && (
                            <p className="text-sm text-stone-600">
                                {t("admin.companies.password_no_mail")}
                            </p>
                        )}
                        {mailOn && (
                            <Toggle
                                label={t("admin.companies.set_password")}
                                description={
                                    setPassword
                                        ? t("admin.companies.set_password_on")
                                        : t("admin.companies.set_password_off")
                                }
                                checked={setPassword}
                                onChange={setSetPassword}
                            />
                        )}
                        {(setPassword || !mailOn) && (
                            <>
                                <Input
                                    label={t("fields.password")}
                                    type="text"
                                    autoComplete="off"
                                    hint={t("fields.password_hint")}
                                    value={form.password}
                                    error={errors.password}
                                    onChange={set("password")}
                                />
                                <Toggle
                                    label={t("admin.companies.mark_verified")}
                                    description={t(
                                        "admin.companies.mark_verified_text",
                                    )}
                                    checked={form.mark_verified}
                                    onChange={(v) =>
                                        setForm({ ...form, mark_verified: v })
                                    }
                                />
                            </>
                        )}
                    </div>
                    <div className="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
                        <Button variant="ghost" to="/admin/companies">
                            {t("common.cancel")}
                        </Button>
                        <Button type="submit" loading={busy}>
                            {t("admin.companies.create")}
                        </Button>
                    </div>
                </Card>
            </form>
        </div>
    );
}

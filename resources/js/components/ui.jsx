import { useEffect, useId, useRef } from 'react';
import { Link } from 'react-router';
import { useTranslation } from 'react-i18next';
import { AlertCircle, CheckCircle2, Info, Loader2, X } from 'lucide-react';

/*
 * Small design system for the app. Calm, spacious, touch friendly:
 * controls are at least 44px high on mobile.
 */

const cx = (...classes) => classes.filter(Boolean).join(' ');

const buttonVariants = {
    primary: 'bg-brand-600 text-white hover:bg-brand-700 disabled:bg-brand-600/50',
    secondary: 'bg-white text-stone-800 ring-1 ring-stone-200 hover:bg-stone-50 disabled:text-stone-400',
    ghost: 'text-stone-600 hover:bg-stone-100 hover:text-stone-900',
    danger: 'bg-red-600 text-white hover:bg-red-700 disabled:bg-red-600/50',
    'danger-ghost': 'text-red-600 hover:bg-red-50',
};

const buttonSizes = {
    sm: 'h-9 px-3 text-sm',
    md: 'h-11 px-4 text-sm',
    lg: 'h-14 px-6 text-base',
};

export function Button({ variant = 'primary', size = 'md', loading = false, icon: Icon, className, children, to, ...props }) {
    const classes = cx(
        'inline-flex items-center justify-center gap-2 rounded-xl font-medium transition-colors disabled:cursor-not-allowed',
        buttonVariants[variant],
        buttonSizes[size],
        className,
    );
    const content = (
        <>
            {loading ? <Loader2 className="size-4 animate-spin" aria-hidden /> : Icon && <Icon className="size-4" aria-hidden />}
            {children}
        </>
    );

    if (to) {
        return (
            <Link to={to} className={classes} {...props}>
                {content}
            </Link>
        );
    }

    return (
        <button type="button" className={classes} {...props} disabled={loading || props.disabled}>
            {content}
        </button>
    );
}

export function Field({ label, error, hint, children, id }) {
    return (
        <div className="space-y-1.5">
            {label && (
                <label htmlFor={id} className="block text-sm font-medium text-stone-700">
                    {label}
                </label>
            )}
            {children}
            {error ? (
                <p className="text-sm text-red-600" role="alert">
                    {error}
                </p>
            ) : (
                hint && <p className="text-sm text-stone-500">{hint}</p>
            )}
        </div>
    );
}

const controlClasses = (error) =>
    cx(
        'block w-full rounded-xl border bg-white px-3.5 text-base text-stone-900 shadow-xs transition sm:text-sm',
        'placeholder:text-stone-400 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 focus:outline-none',
        error ? 'border-red-300' : 'border-stone-200',
    );

export function Input({ label, error, hint, className, ...props }) {
    const id = useId();
    return (
        <Field label={label} error={error} hint={hint} id={id}>
            <input id={id} className={cx(controlClasses(error), 'h-11', className)} aria-invalid={!!error} {...props} />
        </Field>
    );
}

export function Select({ label, error, hint, options, className, ...props }) {
    const id = useId();
    return (
        <Field label={label} error={error} hint={hint} id={id}>
            <select id={id} className={cx(controlClasses(error), 'h-11 pr-8', className)} aria-invalid={!!error} {...props}>
                {options.map((o) => (
                    <option key={o.value} value={o.value}>
                        {o.label}
                    </option>
                ))}
            </select>
        </Field>
    );
}

export function Toggle({ label, description, checked, onChange, disabled }) {
    const id = useId();
    return (
        <label htmlFor={id} className="flex cursor-pointer items-start justify-between gap-4 py-1">
            <span>
                <span className="block text-sm font-medium text-stone-800">{label}</span>
                {description && <span className="block text-sm text-stone-500">{description}</span>}
            </span>
            <span className="relative mt-0.5 inline-flex shrink-0">
                <input
                    id={id}
                    type="checkbox"
                    role="switch"
                    className="peer sr-only"
                    checked={checked}
                    disabled={disabled}
                    onChange={(e) => onChange(e.target.checked)}
                />
                <span className="h-6 w-11 rounded-full bg-stone-300 transition peer-checked:bg-brand-600 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500/40" />
                <span className="absolute top-0.5 left-0.5 size-5 rounded-full bg-white shadow transition peer-checked:translate-x-5" />
            </span>
        </label>
    );
}

export function Card({ className, children, padded = true }) {
    return <div className={cx('rounded-2xl bg-white ring-1 ring-stone-200/80', padded && 'p-5 sm:p-6', className)}>{children}</div>;
}

const alertStyles = {
    error: ['bg-red-50 text-red-800 ring-red-200', AlertCircle],
    success: ['bg-emerald-50 text-emerald-800 ring-emerald-200', CheckCircle2],
    info: ['bg-sky-50 text-sky-800 ring-sky-200', Info],
    warning: ['bg-amber-50 text-amber-900 ring-amber-200', AlertCircle],
};

export function Alert({ type = 'info', children, onClose, className }) {
    const { t } = useTranslation();
    if (!children) return null;
    const [styles, Icon] = alertStyles[type];
    return (
        <div className={cx('flex items-start gap-3 rounded-xl p-3.5 text-sm ring-1', styles, className)} role={type === 'error' ? 'alert' : 'status'}>
            <Icon className="mt-0.5 size-4 shrink-0" aria-hidden />
            <div className="flex-1">{children}</div>
            {onClose && (
                <button type="button" onClick={onClose} className="-my-2.5 -mr-2.5 grid size-10 shrink-0 place-items-center rounded-lg opacity-60 hover:opacity-100" aria-label={t('common.close')}>
                    <X className="size-4" aria-hidden />
                </button>
            )}
        </div>
    );
}

const badgeTones = {
    green: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    red: 'bg-red-50 text-red-700 ring-red-200',
    amber: 'bg-amber-50 text-amber-800 ring-amber-200',
    grey: 'bg-stone-100 text-stone-600 ring-stone-200',
    brand: 'bg-brand-50 text-brand-700 ring-brand-200',
};

export function Badge({ tone = 'grey', children }) {
    return <span className={cx('inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ring-1', badgeTones[tone])}>{children}</span>;
}

export function Spinner({ className }) {
    return <Loader2 className={cx('size-5 animate-spin text-stone-400', className)} aria-hidden />;
}

export function FullPageSpinner() {
    return (
        <div className="flex min-h-dvh items-center justify-center">
            <Spinner className="size-7" />
        </div>
    );
}

/** Shown inside a layout while a lazily loaded page arrives. */
export function PageLoading() {
    return (
        <div className="flex justify-center py-16">
            <Spinner className="size-6" />
        </div>
    );
}

export function PageHeader({ title, description, actions }) {
    return (
        <div className="mb-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 className="text-2xl font-semibold tracking-tight text-stone-900">{title}</h1>
                {description && <p className="mt-1 text-stone-500">{description}</p>}
            </div>
            {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
        </div>
    );
}

export function StatCard({ label, value, hint }) {
    return (
        <Card>
            <p className="text-sm text-stone-500">{label}</p>
            <p className="mt-2 text-2xl font-semibold tracking-tight">{value}</p>
            {hint && <p className="mt-1 text-xs text-stone-400">{hint}</p>}
        </Card>
    );
}

export function EmptyState({ icon: Icon, title, description, action }) {
    return (
        <div className="flex flex-col items-center px-6 py-14 text-center">
            {Icon && (
                <div className="mb-4 rounded-2xl bg-stone-100 p-3">
                    <Icon className="size-6 text-stone-500" aria-hidden />
                </div>
            )}
            <p className="font-medium text-stone-800">{title}</p>
            {description && <p className="mt-1 max-w-sm text-sm text-stone-500">{description}</p>}
            {action && <div className="mt-5">{action}</div>}
        </div>
    );
}

/** Accessible modal built on the native <dialog> element. */
export function Modal({ open, onClose, title, children, footer }) {
    const { t } = useTranslation();
    const ref = useRef(null);

    useEffect(() => {
        const dialog = ref.current;
        if (!dialog) return;
        if (open && !dialog.open) dialog.showModal();
        if (!open && dialog.open) dialog.close();
    }, [open]);

    return (
        <dialog
            ref={ref}
            onClose={onClose}
            onClick={(e) => e.target === ref.current && onClose()}
            className="m-auto w-[calc(100%-2rem)] max-w-lg rounded-2xl p-0 shadow-xl backdrop:bg-stone-900/40"
        >
            {open && (
                <div className="p-6">
                    <div className="mb-4 flex items-start justify-between gap-4">
                        <h2 className="text-lg font-semibold">{title}</h2>
                        <button type="button" onClick={onClose} className="-m-2 grid size-10 shrink-0 place-items-center rounded-lg text-stone-400 hover:text-stone-700" aria-label={t('common.close')}>
                            <X className="size-5" aria-hidden />
                        </button>
                    </div>
                    <div className="space-y-4">{children}</div>
                    {footer && <div className="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">{footer}</div>}
                </div>
            )}
        </dialog>
    );
}

export function Pagination({ meta, onPage, labels }) {
    if (!meta || meta.last_page <= 1) return null;
    return (
        <div className="flex items-center justify-between gap-4 pt-4 text-sm text-stone-500">
            <span>{labels.summary}</span>
            <div className="flex gap-2">
                <Button variant="secondary" size="sm" disabled={meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)}>
                    {labels.previous}
                </Button>
                <Button variant="secondary" size="sm" disabled={meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)}>
                    {labels.next}
                </Button>
            </div>
        </div>
    );
}

export { cx };

import { useTranslation } from 'react-i18next';
import { Check } from 'lucide-react';
import { cx } from '../ui';

const STEPS = ['photos', 'settings', 'optimize', 'results', 'download'];

/**
 * The five-step flow. `current` is 1-based, or an array of steps that are
 * active together (results and download share one screen). `onStep` makes
 * finished steps clickable.
 */
export default function Stepper({ current, onStep }) {
    const { t } = useTranslation();
    const active = Array.isArray(current) ? current : [current];
    const first = Math.min(...active);
    const percent = Math.round(((Math.max(...active) - 0.5) / STEPS.length) * 100);

    return (
        <nav aria-label={t('batch.steps.label')}>
            {/* Phones: "Stap 2 van 5" and a thin bar. */}
            <div className="sm:hidden">
                <p className="text-xs font-medium tracking-wide text-stone-500 uppercase">
                    {t('batch.steps.of', { step: first, total: STEPS.length })}
                </p>
                <p className="mt-0.5 font-semibold">{active.map((s) => t(`batch.steps.${STEPS[s - 1]}`)).join(' & ')}</p>
                <div className="mt-3 h-1.5 overflow-hidden rounded-full bg-stone-200">
                    <div className="h-full rounded-full bg-brand-600 transition-all duration-500" style={{ width: `${percent}%` }} />
                </div>
            </div>

            <ol className="hidden items-center gap-2 sm:flex">
                {STEPS.map((key, index) => {
                    const step = index + 1;
                    const isActive = active.includes(step);
                    const done = step < first;
                    const clickable = done && onStep;
                    const Tag = clickable ? 'button' : 'span';

                    return (
                        <li key={key} className="flex items-center gap-2 last:flex-none [&:not(:last-child)]:flex-1" aria-current={isActive ? 'step' : undefined}>
                            <Tag
                                {...(clickable ? { type: 'button', onClick: () => onStep(step) } : {})}
                                className={cx(
                                    'flex shrink-0 items-center gap-2 rounded-full py-1 pr-3 pl-1 text-sm whitespace-nowrap transition',
                                    isActive && 'bg-white font-medium text-stone-900 shadow-sm ring-1 ring-stone-200',
                                    done && 'text-stone-600',
                                    !done && !isActive && 'text-stone-400',
                                    clickable && 'hover:bg-white',
                                )}
                            >
                                <span
                                    className={cx(
                                        'grid size-7 shrink-0 place-items-center rounded-full text-xs font-semibold',
                                        done && 'bg-brand-600 text-white',
                                        isActive && 'bg-stone-900 text-white',
                                        !done && !isActive && 'bg-stone-200 text-stone-500',
                                    )}
                                >
                                    {done ? <Check className="size-4" aria-hidden /> : step}
                                </span>
                                <span className={cx(!isActive && 'hidden lg:inline')}>{t(`batch.steps.${key}`)}</span>
                            </Tag>
                            {step < STEPS.length && <span className={cx('h-px min-w-3 flex-1', step < first ? 'bg-brand-300' : 'bg-stone-200')} aria-hidden />}
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}

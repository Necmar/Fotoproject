import { useTranslation } from 'react-i18next';
import { Check } from 'lucide-react';
import { cx } from '../ui';

const STEPS = ['photos', 'settings', 'optimize', 'results', 'download'];

/** The five-step flow from the spec. `current` is 1-based. */
export default function Stepper({ current }) {
    const { t } = useTranslation();

    return (
        <ol className="flex items-center gap-2 sm:gap-3" aria-label={t('batch.steps.label')}>
            {STEPS.map((key, index) => {
                const step = index + 1;
                const done = step < current;
                const active = step === current;

                return (
                    <li key={key} className="flex min-w-0 flex-1 items-center gap-2 sm:gap-3" aria-current={active ? 'step' : undefined}>
                        <span
                            className={cx(
                                'grid size-7 shrink-0 place-items-center rounded-full text-xs font-semibold',
                                done && 'bg-brand-600 text-white',
                                active && 'bg-stone-900 text-white',
                                !done && !active && 'bg-stone-200 text-stone-500',
                            )}
                        >
                            {done ? <Check className="size-4" aria-hidden /> : step}
                        </span>
                        <span className={cx('hidden truncate text-sm lg:block', active ? 'font-medium text-stone-900' : 'text-stone-500')}>
                            {t(`batch.steps.${key}`)}
                        </span>
                        {step < STEPS.length && <span className="h-px flex-1 bg-stone-200" aria-hidden />}
                    </li>
                );
            })}
        </ol>
    );
}

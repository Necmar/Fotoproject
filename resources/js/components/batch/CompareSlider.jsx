import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { MoveHorizontal } from 'lucide-react';
import { cx } from '../ui';

/**
 * Before/after comparison. Drag (mouse or touch) or use the arrow keys:
 * the slider is a real range input, so it is accessible and works on phones.
 * Both images share one frame; object-contain keeps them undistorted when a
 * crop changed the aspect ratio.
 */
export default function CompareSlider({ before, after, alt }) {
    const { t } = useTranslation();
    const [position, setPosition] = useState(50);

    return (
        <div className="relative w-full overflow-hidden rounded-2xl bg-stone-100 select-none">
            <img src={after} alt={`${alt} (${t('viewer.after')})`} className="block max-h-[70dvh] w-full object-contain" draggable={false} />
            <img
                src={before}
                alt={`${alt} (${t('viewer.before')})`}
                className="absolute inset-0 size-full object-contain"
                style={{ clipPath: `inset(0 ${100 - position}% 0 0)` }}
                draggable={false}
            />

            <div className="pointer-events-none absolute inset-y-0" style={{ left: `${position}%` }} aria-hidden>
                <div className="absolute inset-y-0 -ml-px w-0.5 bg-white shadow-[0_0_0_1px_rgba(0,0,0,0.15)]" />
                <div className="absolute top-1/2 -ml-5 grid size-10 -translate-y-1/2 place-items-center rounded-full bg-white text-stone-700 shadow-md">
                    <MoveHorizontal className="size-5" />
                </div>
            </div>

            <span className="pointer-events-none absolute top-3 left-3 rounded-full bg-black/55 px-2.5 py-1 text-xs font-medium text-white">{t('viewer.before')}</span>
            <span className="pointer-events-none absolute top-3 right-3 rounded-full bg-black/55 px-2.5 py-1 text-xs font-medium text-white">{t('viewer.after')}</span>

            <input
                type="range"
                min="0"
                max="100"
                step="0.5"
                value={position}
                onChange={(e) => setPosition(Number(e.target.value))}
                aria-label={t('viewer.slider')}
                className={cx('absolute inset-0 size-full cursor-ew-resize appearance-none opacity-0', 'touch-pan-y')}
            />
        </div>
    );
}

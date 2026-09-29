import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { MoveHorizontal } from 'lucide-react';

/**
 * Before/after comparison. Drag or tap anywhere on the photo (mouse, touch,
 * pen via pointer events; iOS does not let a hidden range input be dragged
 * from any point) or use the arrow keys on the focusable range input.
 * Both images share one frame; object-contain keeps them undistorted when a
 * crop changed the aspect ratio.
 */
export default function CompareSlider({ before, after, alt }) {
    const { t } = useTranslation();
    const [position, setPosition] = useState(50);
    const frame = useRef(null);

    const moveTo = (clientX) => {
        const box = frame.current.getBoundingClientRect();
        setPosition(Math.min(100, Math.max(0, ((clientX - box.left) / box.width) * 100)));
    };
    const onPointerDown = (e) => {
        if (e.button > 0) return;
        e.currentTarget.setPointerCapture(e.pointerId);
        moveTo(e.clientX);
    };
    const onPointerMove = (e) => e.currentTarget.hasPointerCapture(e.pointerId) && moveTo(e.clientX);

    return (
        <div
            ref={frame}
            onPointerDown={onPointerDown}
            onPointerMove={onPointerMove}
            className="relative w-full cursor-ew-resize touch-pan-y overflow-hidden rounded-2xl bg-stone-100 select-none"
        >
            <input
                type="range"
                min="0"
                max="100"
                step="0.5"
                value={position}
                onChange={(e) => setPosition(Number(e.target.value))}
                aria-label={t('viewer.slider')}
                className="peer pointer-events-none absolute inset-0 size-full appearance-none opacity-0"
            />
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
                <div className="absolute top-1/2 -ml-5 grid size-10 -translate-y-1/2 place-items-center rounded-full bg-white text-stone-700 shadow-md ring-brand-600 [.peer:focus-visible~div_&]:ring-2">
                    <MoveHorizontal className="size-5" />
                </div>
            </div>

            <span className="pointer-events-none absolute top-3 left-3 rounded-full bg-black/55 px-2.5 py-1 text-xs font-medium text-white">{t('viewer.before')}</span>
            <span className="pointer-events-none absolute top-3 right-3 rounded-full bg-black/55 px-2.5 py-1 text-xs font-medium text-white">{t('viewer.after')}</span>
        </div>
    );
}

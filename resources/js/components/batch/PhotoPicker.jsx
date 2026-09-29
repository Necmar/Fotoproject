import { useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { Camera, ImagePlus } from 'lucide-react';
import { cx } from '../ui';

const ACCEPT = 'image/jpeg,image/png,image/heic,image/heif,.jpg,.jpeg,.png,.heic,.heif';

/**
 * Large, touch-friendly picker. "Foto's kiezen" opens the photo library /
 * file dialog (multiple selection); "Camera" opens the camera directly on
 * phones. Desktop also supports drag and drop.
 */
export default function PhotoPicker({ onFiles, disabled, remaining, compact = false }) {
    const { t } = useTranslation();
    const library = useRef(null);
    const camera = useRef(null);

    const pick = (e) => {
        onFiles(e.target.files);
        e.target.value = ''; // allow picking the same file again
    };

    return (
        // Drag and drop and paste are handled for the whole page (BatchEdit).
        <div
            className={cx(
                'rounded-3xl border-2 border-dashed border-stone-200 bg-white text-center transition-colors hover:border-brand-300',
                compact ? 'px-4 py-5' : 'px-6 py-10 sm:py-14',
                disabled && 'opacity-60',
            )}
        >
            {!compact && (
                <>
                    <div className="mx-auto mb-4 grid size-14 place-items-center rounded-2xl bg-brand-50">
                        <ImagePlus className="size-7 text-brand-700" aria-hidden />
                    </div>
                    <p className="text-lg font-semibold">{t('upload.title')}</p>
                    <p className="mx-auto mt-1 max-w-md text-sm text-stone-500">{t('upload.hint')}</p>
                </>
            )}

            <div className={cx('flex flex-col justify-center gap-3 sm:flex-row', !compact && 'mt-6')}>
                <button
                    type="button"
                    disabled={disabled}
                    onClick={() => library.current?.click()}
                    className="inline-flex h-14 items-center justify-center gap-2 rounded-2xl bg-brand-600 px-6 text-base font-medium text-white hover:bg-brand-700 disabled:cursor-not-allowed"
                >
                    <ImagePlus className="size-5" aria-hidden />
                    {compact ? t('upload.add_more') : t('upload.choose')}
                </button>
                <button
                    type="button"
                    disabled={disabled}
                    onClick={() => camera.current?.click()}
                    className="inline-flex h-14 items-center justify-center gap-2 rounded-2xl bg-white px-6 text-base font-medium text-stone-800 ring-1 ring-stone-200 hover:bg-stone-50 disabled:cursor-not-allowed sm:hidden"
                >
                    <Camera className="size-5" aria-hidden />
                    {t('upload.camera')}
                </button>
            </div>

            <p className="mt-4 text-xs text-stone-400">
                {disabled ? t('upload.full') : t('upload.remaining', { count: remaining })}
            </p>

            <input ref={library} type="file" accept={ACCEPT} multiple hidden onChange={pick} />
            <input ref={camera} type="file" accept="image/*" capture="environment" hidden onChange={pick} />
        </div>
    );
}

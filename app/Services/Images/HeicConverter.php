<?php

namespace App\Services\Images;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Server-side HEIC/HEIF to JPEG. Only a fallback: the React app converts
 * HEIC in the browser before uploading whenever it can.
 *
 * Tries, in order: the Imagick extension (with a HEIC delegate), then the
 * `heif-convert` or ImageMagick CLI when proc_open is allowed. Shared hosting
 * often has none of these; convert() then returns false and the upload is
 * refused with a clear "conversion failed" message.
 */
class HeicConverter
{
    public function isAvailable(): bool
    {
        return $this->imagickSupportsHeic() || $this->cliBinary() !== null;
    }

    public function convert(string $source, string $targetJpeg): bool
    {
        try {
            if ($this->imagickSupportsHeic()) {
                $im = new \Imagick($source);
                $im->setIteratorIndex(0);
                $im->autoOrient();
                $im->setImageFormat('jpeg');
                $im->setImageCompressionQuality(92);
                $im->stripImage();
                $ok = $im->writeImage($targetJpeg);
                $im->clear();

                if ($ok && is_file($targetJpeg)) {
                    return true;
                }
            }

            if ($binary = $this->cliBinary()) {
                return $this->runCli($binary, $source, $targetJpeg);
            }
        } catch (Throwable $e) {
            Log::warning('HEIC conversion failed', ['error' => $e->getMessage()]);
        }

        return false;
    }

    private function imagickSupportsHeic(): bool
    {
        return extension_loaded('imagick') && class_exists(\Imagick::class)
            && in_array('HEIC', \Imagick::queryFormats('HEIC'), true);
    }

    private function cliBinary(): ?string
    {
        if (! function_exists('proc_open') || in_array('proc_open', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
            return null;
        }

        foreach ((array) config('bora.processing.heic_binaries', []) as $candidate) {
            if ($candidate && is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function runCli(string $binary, string $source, string $target): bool
    {
        $isMagick = in_array(basename($binary), ['magick', 'convert'], true);
        $command = $isMagick
            ? [$binary, $source.'[0]', '-auto-orient', '-strip', '-quality', '92', $target]
            : [$binary, '-q', '92', $source, $target];

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            return false;
        }

        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0 && is_file($target) && filesize($target) > 0;
    }
}

<?php

namespace App\Services\OpenAI;

use App\Services\Images\ImageEditor;
use App\Services\Storage\LocalFiles;

/** Prepares images for the OpenAI API: small JPEG data URLs for analysis, files for edits. */
class ImageInput
{
    public function __construct(private readonly LocalFiles $files) {}

    /** base64 JPEG data URL, longest side at most $maxSide (fewer tokens, same judgement). */
    public function dataUrl(string $diskPath, ?int $maxSide = null): string
    {
        $maxSide ??= (int) config('services.openai.analysis_image_side', 1024);
        $local = $this->files->localPath($diskPath);
        $tmp = $this->files->tempPath('jpg');

        try {
            ImageEditor::open($local)->fitWithin($maxSide)->saveJpeg($tmp, 85);

            return 'data:image/jpeg;base64,'.base64_encode((string) file_get_contents($tmp));
        } finally {
            $this->files->release($local);
            @unlink($tmp);
        }
    }

    /**
     * Local JPEG for the edit endpoint (longest side <= $maxSide). Caller deletes it.
     *
     * @return array{path: string, width: int, height: int}
     */
    public function editFile(string $diskPath, int $maxSide): array
    {
        $local = $this->files->localPath($diskPath);
        $tmp = $this->files->tempPath('jpg');

        try {
            $editor = ImageEditor::open($local)->fitWithin($maxSide);
            $editor->saveJpeg($tmp, 92);

            return ['path' => $tmp, 'width' => $editor->width(), 'height' => $editor->height()];
        } finally {
            $this->files->release($local);
        }
    }
}

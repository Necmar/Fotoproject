<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Lifecycle of a single image. The UI maps these to the Dutch labels
 * Wachten / Uploaden / Analyseren / Optimaliseren / Afronden / Klaar / Mislukt.
 */
enum ImageStatus: string
{
    use HasValues;

    case Uploading = 'uploading';
    case Uploaded = 'uploaded';
    case Queued = 'queued';
    case Analyzing = 'analyzing';
    case Processing = 'processing';
    case Finalizing = 'finalizing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function isFinished(): bool
    {
        return $this === self::Completed || $this === self::Failed;
    }

    public function isBusy(): bool
    {
        return in_array($this, [self::Analyzing, self::Processing, self::Finalizing], true);
    }
}

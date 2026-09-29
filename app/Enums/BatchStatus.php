<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum BatchStatus: string
{
    use HasValues;

    case Draft = 'draft';
    case Uploading = 'uploading';
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case CompletedWithErrors = 'completed_with_errors';
    case Failed = 'failed';

    public function isFinished(): bool
    {
        return in_array($this, [self::Completed, self::CompletedWithErrors, self::Failed], true);
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Uploading, self::Queued, self::Processing], true);
    }

    /** @return list<self> */
    public static function activeCases(): array
    {
        return [self::Uploading, self::Queued, self::Processing];
    }
}

<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/** Kind of work recorded in image_processing_records (used for usage stats). */
enum ProcessingType: string
{
    use HasValues;

    case Analysis = 'analysis';
    case Edit = 'edit';
    case Reoptimize = 'reoptimize';
    case Local = 'local';
}

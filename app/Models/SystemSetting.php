<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Key/value store for runtime settings. Use App\Services\SystemSettings, not this model directly. */
#[Fillable(['key', 'value'])]
class SystemSetting extends Model
{
    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}

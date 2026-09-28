<?php

namespace App\Services\Storage;

use App\Models\Batch;
use App\Models\Company;
use Illuminate\Support\Facades\DB;

/** Keeps batch and company storage counters in sync with files on disk. */
class StorageAccounting
{
    /** Positive bytes add, negative bytes subtract (never below zero). */
    public function add(Batch $batch, int $bytes): void
    {
        if ($bytes === 0) {
            return;
        }

        DB::transaction(function () use ($batch, $bytes) {
            Batch::query()->whereKey($batch->getKey())->update(['storage_bytes' => $this->expression($bytes)]);
            Company::query()->whereKey($batch->company_id)->update(['storage_bytes' => $this->expression($bytes)]);
        });
    }

    /** Portable (MySQL/MariaDB/SQLite) and safe for unsigned columns. */
    private function expression(int $bytes)
    {
        $amount = abs($bytes);

        return $bytes > 0
            ? DB::raw("storage_bytes + {$amount}")
            : DB::raw("CASE WHEN storage_bytes > {$amount} THEN storage_bytes - {$amount} ELSE 0 END");
    }
}

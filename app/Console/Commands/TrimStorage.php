<?php

namespace App\Console\Commands;

use App\Services\Storage\StorageTrimmer;
use Illuminate\Console\Command;

/** Hourly: removes files the app no longer needs (originals, used AI results, old ZIPs). */
class TrimStorage extends Command
{
    protected $signature = 'bora:trim-storage {--dry-run : Alleen tonen wat verwijderd zou worden}';

    protected $description = 'Verwijdert originelen, gebruikte AI-bestanden en oude ZIP-bestanden';

    public function handle(StorageTrimmer $trimmer): int
    {
        $dry = (bool) $this->option('dry-run');
        $images = $trimmer->trimExisting($dry);
        $zips = $trimmer->trimZips((int) config('bora.storage.zip_hours', 48), $dry);

        $this->info(($dry ? '[dry-run] ' : '')."Foto's opgeschoond: {$images}, ZIP-bestanden verwijderd: {$zips}");

        return self::SUCCESS;
    }
}

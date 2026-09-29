<?php

namespace App\Services;

use App\Enums\ActivityAction;
use App\Exceptions\DomainRuleException;
use App\Models\Company;
use App\Services\Images\ImageEditor;
use App\Services\Images\ImageTypeDetector;
use App\Services\Storage\LocalFiles;
use App\Services\Storage\StorageAccounting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Company logo for watermarks. Stored as PNG (transparency kept), at most
 * 1200 px, without metadata, on the private disk. Only the owning company
 * can fetch it.
 */
class LogoService
{
    public const MAX_SIDE = 1200;

    public const MAX_MB = 5;

    public function __construct(
        private readonly ImageTypeDetector $detector,
        private readonly LocalFiles $files,
        private readonly StorageAccounting $storage,
        private readonly ActivityLogger $activity,
    ) {}

    public function store(Company $company, UploadedFile $file): Company
    {
        $mime = $this->detector->detect($file->getRealPath());

        if (! in_array($mime, [ImageTypeDetector::PNG, ImageTypeDetector::JPEG], true)) {
            throw new DomainRuleException('logo_invalid');
        }

        $editor = ImageEditor::open($file->getRealPath())->fitWithin(self::MAX_SIDE);
        $path = "companies/{$company->id}/logo/".Str::ulid()->toBase32().'.png';
        $local = $this->files->writablePath($path);
        $editor->savePng($local);
        $bytes = $this->files->commit($local, $path);

        $this->removeFile($company);
        $company->forceFill(['logo_path' => $path])->save();
        $this->storage->addToCompany($company->id, $bytes);
        $this->activity->log(ActivityAction::LogoChanged, $company, ['action' => 'uploaded'], company: $company);

        return $company;
    }

    public function delete(Company $company): void
    {
        $this->removeFile($company);
        $company->forceFill(['logo_path' => null])->save();
        $this->activity->log(ActivityAction::LogoChanged, $company, ['action' => 'removed'], company: $company);
    }

    private function removeFile(Company $company): void
    {
        $old = $company->logo_path;
        $disk = $this->files->disk();

        if ($old && $disk->exists($old)) {
            $this->storage->addToCompany($company->id, -(int) $disk->size($old));
            $disk->delete($old);
        }
    }
}

<?php

namespace App\Http\Resources;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Company settings as seen by the company owner. @mixin Company */
class CompanySettingsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $s = $this->settingsOrDefault();

        return [
            'company_name' => $this->name,
            'has_logo' => $this->logo_path !== null,
            'default_output_format' => $s->default_output_format->value,
            'default_resolution' => $s->default_resolution->value,
            'default_aspect_ratio' => $s->default_aspect_ratio->value,
            'default_strength' => $s->default_strength->value,
            'default_background' => $s->default_background->value,
            'default_watermark_mode' => $s->default_watermark_mode->value,
            'default_watermark_position' => $s->default_watermark_position->value,
            'default_watermark_opacity' => $s->default_watermark_opacity,
            'filename_prefix' => $s->filename_prefix,
        ];
    }
}

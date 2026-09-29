<?php

namespace App\Http\Controllers\Api;

use App\Enums\AspectRatio;
use App\Enums\BackgroundOption;
use App\Enums\OptimizationStrength;
use App\Enums\OutputFormat;
use App\Enums\Resolution;
use App\Enums\WatermarkMode;
use App\Enums\WatermarkPosition;
use App\Http\Controllers\Controller;
use App\Services\SystemSettings;
use Illuminate\Http\JsonResponse;

/** Public app configuration for the React app (no secrets). */
class MetaController extends Controller
{
    public function __invoke(SystemSettings $settings): JsonResponse
    {
        return response()->json([
            'data' => [
                'app_name' => config('app.name'),
                'locales' => config('bora.locales'),
                'default_locale' => config('app.locale'),
                'registration_enabled' => $settings->registrationEnabled(),
                'max_images_per_batch' => $settings->maxImagesPerBatch(),
                'max_upload_mb' => $settings->maxUploadMb(),
                'retention_days' => $settings->retentionDays(),
                'mail_enabled' => $settings->mailEnabled(),
                'maintenance_message' => $settings->get('maintenance_message'),
                'options' => [
                    'output_format' => OutputFormat::values(),
                    'resolution' => Resolution::values(),
                    'aspect_ratio' => AspectRatio::values(),
                    'strength' => OptimizationStrength::values(),
                    'background' => BackgroundOption::values(),
                    'watermark_mode' => WatermarkMode::values(),
                    'watermark_position' => WatermarkPosition::values(),
                ],
            ],
        ]);
    }
}

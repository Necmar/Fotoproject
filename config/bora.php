<?php

/*
|--------------------------------------------------------------------------
| Bora Foto application configuration
|--------------------------------------------------------------------------
|
| Environment-level defaults. Values that the Super Admin may change at
| runtime (retention, registration, JPG quality, ...) live in the
| system_settings table and are read through App\Services\SystemSettings,
| which falls back to the 'system_defaults' below.
|
*/

return [

    'super_admin' => [
        'name' => env('SUPER_ADMIN_NAME', 'Super Admin'),
        'email' => env('SUPER_ADMIN_EMAIL'),
        'password' => env('SUPER_ADMIN_PASSWORD'),
    ],

    // Private disk for all uploads/results. Switch to an S3-compatible disk later.
    'disk' => env('BORA_DISK', 'local'),

    // URL of the React app (same domain by default). Used for links in e-mails.
    'frontend_url' => env('FRONTEND_URL', env('APP_URL')),

    'system_defaults' => [
        'retention_days' => (int) env('BORA_RETENTION_DAYS', 7),
        'registration_enabled' => (bool) env('BORA_REGISTRATION_ENABLED', false),
        'jpg_quality' => (int) env('BORA_JPG_QUALITY', 88),
        'max_images_per_batch' => 30,
        'max_upload_mb' => (int) env('BORA_MAX_UPLOAD_MB', 25),
        'ai_enabled' => (bool) env('BORA_AI_ENABLED', true),
        'maintenance_message' => null,
    ],

    // Hard limits the Super Admin cannot exceed from the UI.
    'limits' => [
        'jpg_quality_min' => 85,
        'jpg_quality_max' => 90,
        'max_images_per_batch' => 30,
        'retention_days_min' => 1,
        'retention_days_max' => 30,
        'max_upload_mb_max' => 50,
    ],

    'company_defaults' => [
        'default_output_format' => 'jpg',
        'default_resolution' => '2000',
        'default_aspect_ratio' => 'original',
        'default_strength' => 'normal',
        'default_background' => 'keep',
        'default_watermark_mode' => 'none',
        'default_watermark_position' => 'bottom_right',
        'default_watermark_opacity' => 70,
        'filename_prefix' => 'foto',
    ],

    'locales' => ['nl', 'en'],

];

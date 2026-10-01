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
        // AI edit quality: "medium" is roughly twice as fast (and cheaper) as "high".
        'ai_image_quality' => env('OPENAI_IMAGE_QUALITY', 'medium'),
        // "Opnieuw optimaliseren" costs AI edits. 0 = no limit. Set in Super Admin > Systeem.
        // Fixed AI retouch prompt for every photo; empty = resources/prompts/retouch.txt.
        'retouch_prompt' => '',
        'reoptimize_per_image' => 0, // not from .env: older installs had 5 there
        'reoptimize_per_company_per_day' => (int) env('BORA_REOPTIMIZE_PER_DAY', 300),
        'maintenance_message' => null,
    ],

    // Hard limits the Super Admin cannot exceed from the UI.
    // "*" trusts the direct peer (Plesk's nginx). Narrow it with a comma separated
    // list of proxy IPs when Apache could also be reached directly.
    'trusted_proxies' => env('TRUSTED_PROXIES', '*'),

    // E-mail on/off. Normally managed by the Super Admin (Systeem > E-mail);
    // BORA_MAIL_ENABLED=true/false in .env overrides that. See MailSettings.
    'mail_enabled' => env('BORA_MAIL_ENABLED'),

    // Content Security Policy header (SecurityHeaders middleware). Escape hatch: BORA_CSP=false.
    'csp' => (bool) env('BORA_CSP', true),

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

    /*
    | Queue on shared hosting: a cronjob starts `bora:work` every minute. It
    | processes jobs for at most `max_time` seconds and then stops, so no
    | permanent worker is needed. On a VPS: QUEUE_CONNECTION=redis and a
    | supervisor-managed `php artisan queue:work --queue=images,default`.
    */
    'queue' => [
        'images' => env('BORA_QUEUE_IMAGES', 'images'),
        'max_time' => (int) env('BORA_WORKER_MAX_TIME', 50),
        // Photos processed at the same time. Mostly waiting on OpenAI, so a few
        // parallel workers speed a batch up a lot without much server load.
        'workers' => max(1, (int) env('BORA_WORKERS', 3)),
        // Start processing right after "Optimaliseren" (in the web request, after
        // the response is sent) instead of waiting for the next cron minute.
        // auto = only where PHP-FPM can finish the response first; always/never.
        'web_kick' => env('BORA_WEB_KICK', 'auto'),
        'web_max_time' => (int) env('BORA_WEB_WORKER_MAX_TIME', 25),
        // Seconds the short-lived worker waits when the queue is empty before it stops.
        'worker_sleep' => (int) env('BORA_WORKER_SLEEP', 1),
        // Worst case for one photo: analysis (90 s) + up to 3 edits (3 x 180 s:
        // retouch, cut-out, background) + check (90 s) + margin for local work.
        // The queue's retry_after (DB_QUEUE_RETRY_AFTER) must stay larger: +120 s.
        'job_timeout' => (int) env('BORA_JOB_TIMEOUT', 840),
        'tries' => (int) env('BORA_JOB_TRIES', 3),
        // Seconds between retries (exponential).
        'backoff' => [30, 120, 300],
        // Secret for the "fetch a URL" cron alternative (/cron/{token}); empty = disabled.
        'cron_token' => env('BORA_CRON_TOKEN'),
        // Images without progress for this many minutes are handed out again.
        'stuck_after_minutes' => 20,
    ],

    'storage' => [
        // The uploaded original is deleted once the working copy exists (the app never shows it).
        'keep_originals' => (bool) env('BORA_KEEP_ORIGINALS', false),
        // Original and AI result are removed this many hours after they were last needed.
        'trim_grace_hours' => (int) env('BORA_TRIM_GRACE_HOURS', 5),
        // ZIP downloads are removed after this many hours and rebuilt on request.
        'zip_hours' => (int) env('BORA_ZIP_HOURS', 48),
    ],

    'processing' => [
        // Internal working copy: orientation fixed, metadata stripped, JPEG.
        // Large enough for the 2560 px output and as AI input.
        // The largest output is 2560 px: a bigger working copy only costs disk space.
        'working_max_side' => (int) env('BORA_WORKING_MAX_SIDE', 2560),
        'working_quality' => 90,
        'thumbnail_side' => 480,
        'preview_side' => 720,
        'thumbnail_quality' => 80,
        // Raised temporarily for large photos when the host allows ini_set.
        'memory_limit' => env('BORA_PROCESSING_MEMORY', '1024M'),
        // Optional CLI converters for HEIC when Imagick lacks HEIC support.
        'heic_binaries' => array_filter(explode(',', (string) env('BORA_HEIC_BINARIES', '/usr/bin/heif-convert,/usr/bin/magick,/usr/bin/convert'))),
        // Laplacian variance below this (on a 256 px sample) = possibly blurry.
        'blur_threshold' => 18,
        // dHash Hamming distance at or below this = very similar photos.
        'duplicate_threshold' => 6,
    ],

];

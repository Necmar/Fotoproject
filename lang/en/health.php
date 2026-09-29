<?php

// Installation check (Super Admin > Overzicht, php artisan bora:doctor).
return [
    'php_version' => ['label' => 'PHP version', 'hint' => 'Choose PHP 8.3 or 8.4 in Plesk > PHP Settings.'],
    'extensions' => ['label' => 'PHP extensions', 'hint' => 'Enable the missing extensions in Plesk > PHP Settings.'],
    'heic_server' => ['label' => 'HEIC conversion', 'hint' => null, 'server' => 'on the server', 'browser' => 'via the browser'],
    'memory_limit' => ['label' => 'PHP memory_limit', 'hint' => 'Set memory_limit to 512M for processing large photos.'],
    'upload_limits' => ['label' => 'Upload limits', 'hint' => 'Set upload_max_filesize to 32M and post_max_size to 34M.'],
    'max_execution_time' => ['label' => 'Max execution time', 'hint' => 'Set max_execution_time to 120.'],
    'app_key' => ['label' => 'Application key', 'hint' => 'Run "key:generate --force" (see the installation guide).'],
    'debug' => ['label' => 'Debug mode', 'hint' => 'Set APP_DEBUG=false in .env, otherwise users see technical error details.'],
    'environment' => ['label' => 'Environment', 'hint' => 'Set APP_ENV=production in .env.'],
    'https' => ['label' => 'HTTPS', 'hint' => 'Set APP_URL to https://… and enable an SSL certificate in Plesk.'],
    'secure_cookie' => ['label' => 'Secure cookies', 'hint' => 'Set SESSION_SECURE_COOKIE=true in .env.'],
    'storage' => ['label' => 'Write permissions', 'hint' => 'storage/ and bootstrap/cache/ must be writable for PHP.'],
    'disk_space' => ['label' => 'Free disk space', 'hint' => 'Free up space or shorten the retention period.'],
    'cron' => ['label' => 'Cron job', 'hint' => 'The scheduled task is not running: photos are not processed. Check the task in Plesk (every minute).'],
    'mail' => ['label' => 'E-mail', 'hint' => 'E-mail is on, but no mail server is configured. Fill in SMTP under System > E-mail, or switch e-mail off.', 'off' => 'off (not needed)'],
    'openai' => ['label' => 'OpenAI', 'hint' => 'No API key: photos only get local basic corrections. Set OPENAI_API_KEY in .env.'],
    'frontend_build' => ['label' => 'React build', 'hint' => 'public/build is missing: upload the React build (see the installation guide).'],
    'deploy' => ['label' => 'Last update', 'hint' => 'The last update failed. Check storage/logs or run "bora:deploy --force".'],
];

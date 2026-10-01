<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | OpenAI: image analysis (Responses API, vision + structured output) and
    | image editing (Images API). Models are configurable so they can be
    | switched without code changes. The key is only read server-side.
    */
    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'organization' => env('OPENAI_ORGANIZATION'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),

        'analysis_model' => env('OPENAI_ANALYSIS_MODEL', 'gpt-5.4-mini'),
        // Empty = do not send (for models without reasoning support).
        'analysis_reasoning' => env('OPENAI_ANALYSIS_REASONING', 'low'),
        'image_model' => env('OPENAI_IMAGE_MODEL', 'gpt-image-2'),
        'image_quality' => env('OPENAI_IMAGE_QUALITY', 'medium'),
        // How closely the edit follows the source photo; left out automatically if a model refuses it.
        'input_fidelity' => env('OPENAI_INPUT_FIDELITY', 'high'),
        // The whole-photo retouch is what the customer sees: always the finest detail.
        'retouch_quality' => env('OPENAI_RETOUCH_QUALITY', 'high'),

        // "match": ask for the photo's own aspect ratio (multiple of 16, long side <= max_side);
        // falls back to "auto" if the model refuses the size.
        'size_strategy' => env('OPENAI_SIZE_STRATEGY', 'match'),
        'max_side' => (int) env('OPENAI_MAX_SIDE', 2048),
        // Size at which the original product is put on the new background (largest output is 2560).
        'composite_max_side' => (int) env('OPENAI_COMPOSITE_MAX_SIDE', 2560),
        // Largest mean difference (0..1) between the cut-out product and the original after alignment.
        'cutout_max_error' => (float) env('OPENAI_CUTOUT_MAX_ERROR', 0.16),
        'analysis_image_side' => 1024,

        // auto: generative edit only when it is really needed (background/people options,
        //       strong strength, or the analysis recommends it). never: analysis + local only.
        // always: every photo is edited.
        'edit_policy' => env('OPENAI_EDIT_POLICY', 'auto'),
        // Second look after every edit: reject edits that changed the product.
        'verify_edits' => (bool) env('OPENAI_VERIFY_EDITS', true),
        // What happens when the check finds a changed product detail.
        // repair: keep the edit and put the original pixels back in the changed places (text, logos, plate, damage).
        // reject: throw the edit away and use safe local corrections.
        'on_product_change' => env('OPENAI_ON_PRODUCT_CHANGE', 'repair'),

        'timeouts' => [
            'analysis' => (int) env('OPENAI_ANALYSIS_TIMEOUT', 90),
            // One edit (retouch, cut-out or background). Up to 3 per photo must fit in
            // bora.queue.job_timeout together with analysis and check.
            'edit' => (int) env('OPENAI_EDIT_TIMEOUT', 180),
        ],
        // Retries inside one job for 429/5xx (seconds, exponential). Timeouts are
        // never retried inside the job (that could double the time): the queue
        // retries the whole job later, reusing what was already paid for.
        'retry_delays' => [2, 6],

        // USD per 1M tokens, for the cost estimate in the Super Admin.
        'pricing' => [
            'gpt-image-2' => ['input_text' => 2.50, 'input_image' => 4.00, 'output' => 15.00],
            'gpt-image-2.5-flare' => ['input_text' => 5.00, 'input_image' => 8.00, 'output' => 30.00],
            'gpt-image-2.5-sunburst' => ['input_text' => 5.00, 'input_image' => 8.00, 'output' => 30.00],
            'gpt-5.4-mini' => ['input_text' => 0.75, 'input_image' => 0.75, 'output' => 4.50],
        ],
    ],

];

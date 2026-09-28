<?php

return [
    'errors' => [
        'generic' => 'Something went wrong. Please try again later.',
        'forbidden' => 'You do not have access to this page or action.',
        'not_found' => 'Not found.',
        'unauthenticated' => 'You are not signed in.',
        'session_expired' => 'Your session has expired. Refresh the page and try again.',
        'too_many_requests' => 'Too many requests. Please wait a moment and try again.',
        'account_blocked' => 'This account has been blocked. Please contact the administrator.',
        'company_only' => 'This feature is only available for company accounts.',
    ],
    'auth' => [
        'logged_out' => 'You have been signed out.',
        'verification_sent' => 'A new verification link has been sent to your e-mail address.',
        'already_verified' => 'Your e-mail address is already verified.',
        'registration_disabled' => 'Registration is currently not available.',
    ],
    'account' => [
        'password_updated' => 'Your password has been changed.',
    ],
    'admin' => [
        'company_deleted' => 'The company and all its files have been deleted.',
        'no_owner' => 'This company has no owner account.',
        'password_reset_sent' => 'An e-mail has been sent to :email.',
        'storage_deleted' => ':count batch(es) and their files have been deleted.',
        'batch_deleted' => 'The batch and its files have been deleted.',
    ],
    'domain' => [
        'upload_failed' => 'The upload failed. Please try again.',
        'upload_failed_server_limit' => 'The file is larger than the server allows.',
        'file_too_large' => 'This file is too large. At most :max MB per photo.',
        'invalid_type' => 'This file type is not supported. Use JPG, PNG, HEIC or HEIF.',
        'corrupt_file' => 'This file is damaged or not a valid image.',
        'too_many_pixels' => 'This photo has too high a resolution to process.',
        'too_many_images' => 'You can upload at most :max photos per batch.',
        'batch_locked' => 'This batch is already being processed and can no longer be changed.',
        'batch_empty' => 'Please add at least one photo first.',
        'watermark_needs_logo' => 'Upload a company logo first to use a watermark.',
    ],
    'batch' => [
        'deleted' => 'The batch and all its files have been deleted.',
        'image_removed' => 'The photo has been removed.',
    ],
];

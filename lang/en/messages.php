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
];

<?php

return [
    'greeting' => 'Hello :name,',
    'salutation' => "Kind regards,\n:app",
    'invitation' => [
        'subject' => 'Your account at :app',
        'intro' => 'An account for :company has been created at :app. Choose a password to get started.',
        'action' => 'Set password',
        'expire' => 'This link is valid for :days days.',
    ],
    'batch_completed' => [
        'subject' => 'Your photos have been processed: :name',
        'unnamed' => 'unnamed batch',
        'intro' => 'Your images of ":name" have been processed.',
        'succeeded' => 'Succeeded: :count',
        'failed' => 'Failed: :count',
        'action' => 'View results',
        'retention' => 'The photos are available until :date and are deleted automatically after that.',
    ],
];

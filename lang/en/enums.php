<?php

return [
    'user_role' => ['super_admin' => 'Super Admin', 'company_owner' => 'Company owner'],
    'company_status' => ['active' => 'Active', 'blocked' => 'Blocked'],
    'batch_status' => [
        'draft' => 'Draft', 'uploading' => 'Uploading', 'queued' => 'Waiting', 'processing' => 'Processing',
        'completed' => 'Done', 'completed_with_errors' => 'Done with errors', 'failed' => 'Failed',
    ],
    'image_status' => [
        'uploading' => 'Uploading', 'uploaded' => 'Uploaded', 'queued' => 'Waiting', 'analyzing' => 'Analysing',
        'processing' => 'Optimising', 'finalizing' => 'Finishing', 'completed' => 'Done', 'failed' => 'Failed',
    ],
    'output_format' => ['jpg' => 'JPG', 'png' => 'PNG'],
    'resolution' => ['1600' => '1600 px, compact', '2000' => '2000 px, standard', '2560' => '2560 px, high quality'],
    'aspect_ratio' => ['original' => 'Original', '1:1' => '1:1', '4:3' => '4:3', '3:2' => '3:2', '16:9' => '16:9'],
    'optimization_strength' => ['subtle' => 'Subtle', 'normal' => 'Normal', 'strong' => 'Strong'],
    'background_option' => [
        'keep' => 'Keep and improve original background',
        'clean_subtle' => 'Subtly clean up background',
        'remove_distractions' => 'Reduce or remove distracting elements',
        'blur_light' => 'Slightly blur background',
        'remove' => 'Remove background',
        'neutral' => 'Use a neutral background',
    ],
    'watermark_mode' => ['none' => 'No watermark', 'all' => 'Logo on all photos', 'selected' => 'Logo on selected photos'],
    'watermark_position' => [
        'top_left' => 'Top left', 'top_right' => 'Top right', 'bottom_left' => 'Bottom left',
        'bottom_right' => 'Bottom right', 'center' => 'Center',
    ],
    'processing_type' => ['analysis' => 'Analysis', 'edit' => 'AI edit', 'reoptimize' => 'Re-optimise', 'local' => 'Local processing'],
    'activity_action' => [
        'login' => 'Signed in', 'login_failed' => 'Failed sign-in', 'logout' => 'Signed out',
        'password_changed' => 'Password changed', 'password_reset' => 'Password reset',
        'profile_updated' => 'Profile updated', 'email_verified' => 'E-mail verified',
        'company_registered' => 'Company registered', 'company_settings_updated' => 'Company settings updated',
        'logo_changed' => 'Logo changed', 'batch_created' => 'Batch created', 'batch_deleted' => 'Batch deleted',
        'image_processed' => 'Photo processed', 'image_failed' => 'Photo failed',
        'admin_company_created' => 'Company created (admin)', 'admin_company_updated' => 'Company updated (admin)',
        'admin_company_blocked' => 'Company blocked', 'admin_company_unblocked' => 'Company unblocked',
        'admin_company_deleted' => 'Company deleted', 'admin_password_reset_sent' => 'Password reset sent (admin)',
        'admin_storage_deleted' => 'Storage deleted (admin)', 'admin_settings_updated' => 'System settings updated',
    ],
];

<?php

return [
    'user_role' => ['super_admin' => 'Super Admin', 'company_owner' => 'Bedrijfseigenaar'],
    'company_status' => ['active' => 'Actief', 'blocked' => 'Geblokkeerd'],
    'batch_status' => [
        'draft' => 'Concept', 'uploading' => 'Uploaden', 'queued' => 'Wachten', 'processing' => 'Bezig',
        'completed' => 'Klaar', 'completed_with_errors' => 'Klaar met fouten', 'failed' => 'Mislukt',
    ],
    'image_status' => [
        'uploading' => 'Uploaden', 'uploaded' => 'Geüpload', 'queued' => 'Wachten', 'analyzing' => 'Analyseren',
        'processing' => 'Optimaliseren', 'finalizing' => 'Afronden', 'completed' => 'Klaar', 'failed' => 'Mislukt',
    ],
    'output_format' => ['jpg' => 'JPG', 'png' => 'PNG'],
    'resolution' => ['1600' => '1600 px, compact', '2000' => '2000 px, standaard', '2560' => '2560 px, hoge kwaliteit'],
    'aspect_ratio' => ['original' => 'Origineel', '1:1' => '1:1', '4:3' => '4:3', '3:2' => '3:2', '16:9' => '16:9'],
    'optimization_strength' => ['subtle' => 'Subtiel', 'normal' => 'Normaal', 'strong' => 'Sterk'],
    'background_option' => [
        'keep' => 'Originele achtergrond behouden en verbeteren',
        'clean_subtle' => 'Achtergrond subtiel opschonen',
        'remove_distractions' => 'Storende elementen verminderen of verwijderen',
        'blur_light' => 'Achtergrond licht vervagen',
        'remove' => 'Achtergrond verwijderen',
        'neutral' => 'Neutrale achtergrond gebruiken',
    ],
    'watermark_mode' => ['none' => 'Geen watermark', 'all' => 'Logo op alle foto\'s', 'selected' => 'Logo op geselecteerde foto\'s'],
    'watermark_position' => [
        'top_left' => 'Linksboven', 'top_right' => 'Rechtsboven', 'bottom_left' => 'Linksonder',
        'bottom_right' => 'Rechtsonder', 'center' => 'Midden',
    ],
    'processing_type' => ['analysis' => 'Analyse', 'edit' => 'AI-bewerking', 'reoptimize' => 'Opnieuw optimaliseren', 'local' => 'Lokale verwerking'],
    'activity_action' => [
        'login' => 'Ingelogd', 'login_failed' => 'Mislukte inlogpoging', 'logout' => 'Uitgelogd',
        'password_changed' => 'Wachtwoord gewijzigd', 'password_reset' => 'Wachtwoord gereset',
        'profile_updated' => 'Profiel gewijzigd', 'email_verified' => 'E-mailadres geverifieerd',
        'company_registered' => 'Bedrijf geregistreerd', 'company_settings_updated' => 'Bedrijfsinstellingen gewijzigd',
        'logo_changed' => 'Logo gewijzigd', 'batch_created' => 'Batch aangemaakt', 'batch_deleted' => 'Batch verwijderd',
        'image_processed' => 'Foto verwerkt', 'image_failed' => 'Foto mislukt',
        'admin_company_created' => 'Bedrijf aangemaakt (admin)', 'admin_company_updated' => 'Bedrijf gewijzigd (admin)',
        'admin_company_blocked' => 'Bedrijf geblokkeerd', 'admin_company_unblocked' => 'Bedrijf gedeblokkeerd',
        'admin_company_deleted' => 'Bedrijf verwijderd', 'admin_password_reset_sent' => 'Wachtwoordreset verstuurd (admin)',
        'admin_storage_deleted' => 'Opslag verwijderd (admin)', 'admin_settings_updated' => 'Systeeminstellingen gewijzigd',
    ],
];

<?php

return [
    'errors' => [
        'generic' => 'Er ging iets mis. Probeer het later opnieuw.',
        'forbidden' => 'Je hebt geen toegang tot deze pagina of actie.',
        'not_found' => 'Niet gevonden.',
        'unauthenticated' => 'Je bent niet ingelogd.',
        'session_expired' => 'Je sessie is verlopen. Vernieuw de pagina en probeer het opnieuw.',
        'too_many_requests' => 'Te veel verzoeken. Wacht even en probeer het opnieuw.',
        'account_blocked' => 'Dit account is geblokkeerd. Neem contact op met de beheerder.',
        'company_only' => 'Deze functie is alleen beschikbaar voor bedrijfsaccounts.',
    ],
    'auth' => [
        'logged_out' => 'Je bent uitgelogd.',
        'verification_sent' => 'Er is een nieuwe verificatielink naar je e-mailadres gestuurd.',
        'already_verified' => 'Je e-mailadres is al geverifieerd.',
        'registration_disabled' => 'Registratie is momenteel niet mogelijk.',
    ],
    'account' => [
        'password_updated' => 'Je wachtwoord is gewijzigd.',
    ],
    'admin' => [
        'company_deleted' => 'Het bedrijf en alle bestanden zijn verwijderd.',
        'no_owner' => 'Dit bedrijf heeft geen eigenaar-account.',
        'password_reset_sent' => 'Er is een e-mail verstuurd naar :email.',
        'storage_deleted' => ':count batch(es) en bijbehorende bestanden verwijderd.',
        'batch_deleted' => 'De batch en bijbehorende bestanden zijn verwijderd.',
    ],
    'domain' => [
        'upload_failed' => 'Het uploaden is mislukt. Probeer het opnieuw.',
        'upload_failed_server_limit' => 'Het bestand is groter dan de server toestaat.',
        'file_too_large' => 'Dit bestand is te groot. Maximaal :max MB per foto.',
        'invalid_type' => 'Dit bestandstype wordt niet ondersteund. Gebruik JPG, PNG, HEIC of HEIF.',
        'corrupt_file' => 'Dit bestand is beschadigd of geen geldige afbeelding.',
        'too_many_pixels' => 'Deze foto heeft een te hoge resolutie om te verwerken.',
        'too_many_images' => 'Je kunt maximaal :max foto\'s per batch uploaden.',
        'batch_locked' => 'Deze batch wordt al verwerkt en kan niet meer worden gewijzigd.',
        'batch_empty' => 'Voeg eerst minimaal één foto toe.',
        'watermark_needs_logo' => 'Upload eerst een bedrijfslogo om een watermark te gebruiken.',
    ],
    'batch' => [
        'deleted' => 'De batch en alle bijbehorende bestanden zijn verwijderd.',
        'image_removed' => 'De foto is verwijderd.',
    ],
];

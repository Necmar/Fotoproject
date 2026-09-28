<?php

/*
| Dutch validation messages. Rules not listed here fall back to English
| (APP_FALLBACK_LOCALE=en).
*/

return [
    'accepted' => ':Attribute moet geaccepteerd worden.',
    'array' => ':Attribute moet een lijst zijn.',
    'between' => [
        'array' => ':Attribute moet tussen :min en :max items bevatten.',
        'file' => ':Attribute moet tussen :min en :max kilobytes zijn.',
        'numeric' => ':Attribute moet tussen :min en :max liggen.',
        'string' => ':Attribute moet tussen :min en :max tekens zijn.',
    ],
    'boolean' => ':Attribute moet ja of nee zijn.',
    'confirmed' => 'De bevestiging van :attribute komt niet overeen.',
    'current_password' => 'Het huidige wachtwoord is onjuist.',
    'different' => ':Attribute en :other moeten verschillend zijn.',
    'email' => ':Attribute moet een geldig e-mailadres zijn.',
    'enum' => 'De gekozen :attribute is ongeldig.',
    'exists' => 'De gekozen :attribute bestaat niet.',
    'file' => ':Attribute moet een bestand zijn.',
    'image' => ':Attribute moet een afbeelding zijn.',
    'in' => 'De gekozen :attribute is ongeldig.',
    'integer' => ':Attribute moet een geheel getal zijn.',
    'max' => [
        'array' => ':Attribute mag niet meer dan :max items bevatten.',
        'file' => ':Attribute mag niet groter zijn dan :max kilobytes.',
        'numeric' => ':Attribute mag niet hoger zijn dan :max.',
        'string' => ':Attribute mag niet langer zijn dan :max tekens.',
    ],
    'mimes' => ':Attribute moet een bestand zijn van het type: :values.',
    'mimetypes' => ':Attribute moet een bestand zijn van het type: :values.',
    'min' => [
        'array' => ':Attribute moet minimaal :min items bevatten.',
        'file' => ':Attribute moet minimaal :min kilobytes zijn.',
        'numeric' => ':Attribute moet minimaal :min zijn.',
        'string' => ':Attribute moet minimaal :min tekens zijn.',
    ],
    'nullable' => ':Attribute mag leeg zijn.',
    'numeric' => ':Attribute moet een getal zijn.',
    'password' => [
        'letters' => ':Attribute moet minimaal één letter bevatten.',
        'mixed' => ':Attribute moet minimaal één hoofdletter en één kleine letter bevatten.',
        'numbers' => ':Attribute moet minimaal één cijfer bevatten.',
        'symbols' => ':Attribute moet minimaal één speciaal teken bevatten.',
        'uncompromised' => 'Dit :attribute komt voor in een datalek. Kies een ander :attribute.',
    ],
    'regex' => 'Het formaat van :attribute is ongeldig.',
    'required' => ':Attribute is verplicht.',
    'string' => ':Attribute moet tekst zijn.',
    'unique' => ':Attribute is al in gebruik.',
    'uploaded' => 'Het uploaden van :attribute is mislukt.',
    'url' => ':Attribute moet een geldige URL zijn.',

    'attributes' => [
        'name' => 'naam',
        'email' => 'e-mailadres',
        'password' => 'wachtwoord',
        'current_password' => 'huidig wachtwoord',
        'company_name' => 'bedrijfsnaam',
        'owner_name' => 'naam eigenaar',
        'locale' => 'taal',
        'filename_prefix' => 'bestandsnaam-prefix',
        'retention_days' => 'bewaartermijn',
        'jpg_quality' => 'JPG-kwaliteit',
        'max_upload_mb' => 'maximale uploadgrootte',
        'max_images_per_batch' => 'maximum aantal foto\'s per batch',
        'confirm_name' => 'bevestiging',
        'default_watermark_opacity' => 'transparantie',
    ],
];

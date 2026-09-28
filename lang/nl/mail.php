<?php

return [
    'greeting' => 'Hallo :name,',
    'salutation' => "Met vriendelijke groet,\n:app",
    'invitation' => [
        'subject' => 'Je account bij :app',
        'intro' => 'Er is een account voor :company aangemaakt bij :app. Kies een wachtwoord om te beginnen.',
        'action' => 'Wachtwoord instellen',
        'expire' => 'Deze link is :days dagen geldig.',
    ],
    'batch_completed' => [
        'subject' => 'Je foto\'s zijn verwerkt: :name',
        'unnamed' => 'naamloze batch',
        'intro' => 'Je afbeeldingen van ":name" zijn verwerkt.',
        'succeeded' => 'Aantal succesvol: :count',
        'failed' => 'Aantal met fout: :count',
        'action' => 'Bekijk resultaten',
        'retention' => 'De foto\'s zijn beschikbaar tot :date en worden daarna automatisch verwijderd.',
    ],
];

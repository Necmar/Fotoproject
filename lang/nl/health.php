<?php

// Installation check (Super Admin > Overzicht, php artisan bora:doctor).
return [
    'php_version' => ['label' => 'PHP-versie', 'hint' => 'Kies PHP 8.3 of 8.4 in Plesk > PHP-instellingen.'],
    'extensions' => ['label' => 'PHP-extensies', 'hint' => 'Zet de ontbrekende extensies aan in Plesk > PHP-instellingen.'],
    'heic_server' => ['label' => 'HEIC op de server', 'hint' => 'Geen probleem: de browser zet HEIC al om naar JPG. Alleen nodig voor oude browsers.'],
    'memory_limit' => ['label' => 'PHP memory_limit', 'hint' => 'Zet memory_limit op 512M voor de bewerking van grote foto\'s.'],
    'upload_limits' => ['label' => 'Uploadlimieten', 'hint' => 'Zet upload_max_filesize op 32M en post_max_size op 34M.'],
    'max_execution_time' => ['label' => 'Max. uitvoertijd', 'hint' => 'Zet max_execution_time op 120.'],
    'app_key' => ['label' => 'Applicatiesleutel', 'hint' => 'Voer "key:generate --force" uit (zie de installatiehandleiding).'],
    'debug' => ['label' => 'Debugmodus', 'hint' => 'Zet APP_DEBUG=false in .env: anders zien gebruikers technische foutdetails.'],
    'environment' => ['label' => 'Omgeving', 'hint' => 'Zet APP_ENV=production in .env.'],
    'https' => ['label' => 'HTTPS', 'hint' => 'Zet APP_URL op https://… en activeer een SSL-certificaat in Plesk.'],
    'secure_cookie' => ['label' => 'Veilige cookies', 'hint' => 'Zet SESSION_SECURE_COOKIE=true in .env.'],
    'storage' => ['label' => 'Schrijfrechten', 'hint' => 'storage/ en bootstrap/cache/ moeten schrijfbaar zijn voor PHP.'],
    'disk_space' => ['label' => 'Vrije schijfruimte', 'hint' => 'Maak ruimte vrij of verkort de bewaartermijn.'],
    'cron' => ['label' => 'Cronjob', 'hint' => 'De geplande taak draait niet: foto\'s worden niet verwerkt. Controleer de taak in Plesk (iedere minuut).'],
    'mail' => ['label' => 'E-mail', 'hint' => 'Stel SMTP in (MAIL_MAILER=smtp): nu worden geen e-mails verstuurd.'],
    'openai' => ['label' => 'OpenAI', 'hint' => 'Geen API-key: foto\'s krijgen alleen lokale basiscorrecties. Zet OPENAI_API_KEY in .env.'],
    'frontend_build' => ['label' => 'React-build', 'hint' => 'public/build ontbreekt: upload de React-build (zie de installatiehandleiding).'],
    'deploy' => ['label' => 'Laatste update', 'hint' => 'De laatste update is mislukt. Bekijk storage/logs of voer "bora:deploy --force" uit.'],
];

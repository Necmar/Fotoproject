# Fase-rapporten

## Fase 1: Laravel-project, database, authenticatie, bedrijven en Super Admin

**Status:** afgerond. `php artisan test`: 30 tests, 152 assertions, alles groen. React-build slaagt.

### Keuzes

- **Laravel 13.33** (PHP 8.3+), laatste stabiele versie; Cloud86 biedt PHP 8.3/8.4.
- **Sessie-auth zonder extra packages.** De API draait onder de `web`-middleware (`/api/*`), dus cookie-sessies en CSRF (`X-XSRF-TOKEN`). Geen Sanctum, Breeze of Fortify nodig; minder afhankelijkheden op shared hosting.
- **React SPA** geserveerd door één Blade-view (`app.blade.php`), React Router regelt de pagina's.
- **ULID's** voor `batches` en `images`, zodat URL's niet te raden zijn. Policies controleren eigendom altijd.
- **Eén eigenaar per bedrijf** in v1, afgedwongen in de service (niet in de database) zodat medewerkers later kunnen worden toegevoegd.
- **Tellers op `companies`** (`images_processed_total`, `storage_bytes`, ...) en `image_processing_records` blijven bestaan na de 7-dagen-cleanup: statistiek en OpenAI-verbruik gaan niet verloren.
- **Voorbereid op SaaS:** kolommen `plan`, `monthly_image_limit`, `credits_balance` (ongebruikt in v1).
- **Voorbereid op VPS/object storage:** alle bestanden via `config('bora.disk')`; queue via `QUEUE_CONNECTION`.
- **Uitnodiging i.p.v. wachtwoord mailen:** maakt de Super Admin een bedrijf zonder wachtwoord aan, dan krijgt de eigenaar een link (3 dagen geldig) om zelf een wachtwoord te kiezen. Dat bevestigt meteen het e-mailadres.

### Toegevoegd

**Backend**
- `app/Enums/*`: `UserRole`, `CompanyStatus`, `BatchStatus`, `ImageStatus`, `OutputFormat`, `Resolution`, `AspectRatio`, `OptimizationStrength`, `BackgroundOption`, `WatermarkMode`, `WatermarkPosition`, `ProcessingType`, `ActivityAction`, `Concerns/HasValues`
- `app/Models/`: `Company`, `CompanySetting`, `SystemSetting`, `Batch`, `Image`, `ImageProcessingRecord`, `ActivityLog`
- `app/Services/`: `CompanyService`, `CompanyStatsService`, `SystemSettings`, `ActivityLogger`, `Storage/BatchDeletionService`
- `app/Http/Controllers/Api/`: `MetaController`, `Auth/{Session,PasswordReset,EmailVerification,Register}Controller`, `Account/ProfileController`, `Company/SettingsController`, `Admin/{Company,Batch,System}Controller`
- `app/Http/Requests/`: `Auth/{Login,ResetPassword,Register}Request`, `Account/{UpdateProfile,UpdatePassword}Request`, `Company/UpdateCompanySettingsRequest`, `Admin/{StoreCompany,UpdateCompany,UpdateSystemSettings}Request`
- `app/Http/Resources/`: `UserResource`, `CompanySettingsResource`, `Admin/{Company,Batch,ActivityLog}Resource`
- `app/Http/Middleware/`: `EnsureSuperAdmin`, `EnsureCompanyOwner`, `EnsureAccountIsActive`, `SetLocale`
- `app/Policies/`: `CompanyPolicy`, `BatchPolicy`, `ImagePolicy`
- `app/Notifications/CompanyInvitation.php`, `app/Support/FrontendUrl.php`
- `app/Console/Commands/CreateSuperAdmin.php` (`php artisan bora:super-admin`)
- `config/bora.php`
- `routes/api.php`
- Migraties: `0001_01_00_000000_create_companies_table`, `2026_09_28_000100_create_settings_tables`, `2026_09_28_000200_create_batches_and_images_tables`, `2026_09_28_000300_create_activity_logs_table`
- `database/factories/CompanyFactory.php`
- `lang/nl/{auth,passwords,validation,pagination,messages,mail,enums}.php`, `lang/en/{passwords,messages,mail,enums}.php`, `lang/nl.json`
- `tests/Feature/{AuthTest,SuperAdminTest,TenantIsolationTest}.php`

**Frontend** (`resources/js/`)
- `main.jsx`, `App.jsx` (routes)
- `lib/{api,i18n,format,useFetch}.js`, `locales/{nl,en}.json`
- `auth/{AuthContext,guards}.jsx`
- `components/{ui,LanguageSwitcher}.jsx`
- `layouts/{AuthLayout,AppLayout}.jsx`
- `pages/auth/{Login,ForgotPassword,ResetPassword,VerifyEmail,Register}.jsx`
- `pages/account/Account.jsx`
- `pages/company/{Dashboard,Settings}.jsx`
- `pages/admin/{AdminDashboard,Companies,CompanyCreate,CompanyDetail,Batches,SystemSettings,ActivityLog}.jsx`
- `pages/NotFound.jsx`

**Overig:** `README.md`, `docs/PLESK.md`, `docs/PHASES.md`

### Gewijzigd (ten opzichte van de Laravel-skeleton)

`bootstrap/app.php` (API-routes, middleware-aliassen, JSON-foutafhandeling), `app/Models/User.php`, `app/Providers/AppServiceProvider.php` (rate limiters, wachtwoordregels, reset-URL), `app/Http/Controllers/Controller.php`, `config/auth.php` (broker `invites`), `database/migrations/0001_01_01_000000_create_users_table.php`, `database/factories/UserFactory.php`, `database/seeders/DatabaseSeeder.php`, `routes/web.php`, `resources/views/app.blade.php`, `resources/css/app.css`, `vite.config.js`, `composer.json`, `package.json`, `.env.example`.

### Handmatig testen

1. `php artisan migrate:fresh --seed` en `php artisan bora:super-admin`.
2. **Inloggen** als Super Admin: je komt op `/admin`. Als `demo@example.com`: je komt op het bedrijfsdashboard.
3. **Super Admin > Bedrijven:** zoeken, filter op status, nieuw bedrijf zonder wachtwoord (controleer de uitnodigingsmail in de log en open de link `/welcome/...`), nieuw bedrijf met wachtwoord (verificatiemail).
4. **Bedrijf blokkeren** terwijl de eigenaar in een ander browservenster is ingelogd: bij de volgende actie wordt die uitgelogd met een melding. Deblokkeren.
5. **Wachtwoordreset versturen** vanuit Super Admin en de link uit de log gebruiken.
6. **Bedrijf verwijderen:** knop blijft uit tot de exacte bedrijfsnaam is getypt.
7. **Wachtwoord vergeten:** zelfde melding voor bestaand en onbekend e-mailadres.
8. **Mijn account:** naam, taal en e-mail wijzigen (nieuw adres moet opnieuw bevestigd worden), wachtwoord wijzigen.
9. **Bedrijfsinstellingen:** standaardwaarden opslaan; prefix `Auto Jansen` wordt `auto-jansen`.
10. **Systeeminstellingen:** JPG-kwaliteit buiten 85 tot 90 wordt geweigerd; registratie aanzetten toont "Registreren" op de loginpagina.
11. **Taal wisselen** (NL/EN) in de header; na herladen blijft de keuze staan.
12. **Mobiel:** menu via het hamburgericoon, formulieren en knoppen goed bruikbaar op iPhone-breedte.
13. **Beveiliging:** als bedrijfseigenaar `/api/admin/companies` openen geeft 403; 6 foute inlogpogingen geven een wachttijd.

### Nog niet in deze fase

Upload en batchflow (fase 2 en 3), queue-verwerking (4), OpenAI (5), voor/na (6), logo-upload, watermark en downloads (7), geschiedenis, cleanup en e-mail na afronding (8).

## Fase 2: React-dashboard en uploadflow

**Status:** afgerond. `php artisan test`: 47 tests, 264 assertions, alles groen. React-build slaagt. Upload-flow end-to-end getest via HTTP (login, batch, upload, start, origineel ophalen).

### Keuzes

- **Batch als concept op de server.** "Foto's optimaliseren" maakt direct een concept-batch met de bedrijfsstandaarden. De gebruiker kan weggaan en later verder; lege concepten verschijnen niet in de lijsten (opruimen volgt in fase 8).
- **Eén bestand per request**, maximaal 2 tegelijk vanuit de browser, met voortgang per foto. Zo blijft ieder request ruim binnen de POST-, upload- en tijdslimieten van shared hosting.
- **Bestandstype op inhoud (magic bytes)**, niet op extensie of door de browser opgegeven MIME-type. JPEG/PNG worden daarnaast met `getimagesize` gecontroleerd op beschadiging; HEIC/HEIF via de ISO-BMFF `ftyp`-brands.
- **Willekeurige interne bestandsnamen** (`companies/{id}/batches/{ulid}/original/{ulid}.jpg`) op de private disk. Bestanden zijn alleen op te halen via `GET /api/company/images/{id}/{variant}` na een Policy-check.
- **Maximum aantal foto's** wordt onder een database-lock gecontroleerd, zodat parallelle uploads er nooit 31 van maken.
- **Watermark kan alleen met logo**; zonder logo staat het standaard uit en weigert de server een andere keuze (logo-upload volgt in fase 7).
- **Starten** hernummert de foto's 1..n (voor bestandsnamen zonder gaten), zet de batch op `queued` en vergrendelt instellingen en foto's. Het inplannen van jobs volgt in fase 4.
- **Polling** (`usePolling`, iedere 3 seconden, pauzeert als het tabblad verborgen is) op de batchpagina en het dashboard zolang er actieve batches zijn. Geen WebSockets.
- **Preview van HEIC:** browsers behalve Safari kunnen HEIC niet tonen; er verschijnt dan een nette placeholder. Conversie naar JPEG (in de browser en op de server) volgt in fase 3.

### API (nieuw)

| Methode | Pad | Doel |
|---|---|---|
| GET | `/api/company/batches?status=draft\|active\|finished&per_page=` | Eigen batches, nieuwste eerst |
| POST | `/api/company/batches` | Concept aanmaken (optioneel `name` + instellingen) |
| GET | `/api/company/batches/{batch}` | Batch met foto's en voortgang (polling) |
| PATCH | `/api/company/batches/{batch}` | Naam/instellingen van een concept wijzigen |
| DELETE | `/api/company/batches/{batch}` | Batch en alle bestanden verwijderen |
| POST | `/api/company/batches/{batch}/start` | Batch starten (status `queued`) |
| POST | `/api/company/batches/{batch}/images` | Eén foto uploaden (`file`) |
| DELETE | `/api/company/batches/{batch}/images/{image}` | Foto uit concept verwijderen |
| GET | `/api/company/images/{image}/{original\|thumbnail\|optimized}` | Bestand tonen (na autorisatie) |

Foutcodes (`422`, veld `code`): `invalid_type`, `corrupt_file`, `file_too_large`, `too_many_pixels`, `too_many_images`, `batch_locked`, `batch_empty`, `watermark_needs_logo`, `upload_failed`.

### Toegevoegd

- `app/Support/BatchSettings.php` (sleutels, standaarden en validatieregels van batch-instellingen), `app/Support/FileNamer.php`
- `app/Services/BatchService.php`, `app/Services/Images/ImageUploadService.php`, `app/Services/Images/ImageTypeDetector.php`, `app/Services/Storage/StorageAccounting.php`
- `app/Exceptions/DomainRuleException.php`
- `app/Http/Controllers/Api/Company/{BatchController,ImageController}.php`
- `app/Http/Requests/Batch/{SaveBatchRequest,UploadImageRequest}.php`
- `app/Http/Resources/{BatchResource,ImageResource}.php`
- `resources/js/lib/{usePolling,useUploadQueue}.js`
- `resources/js/components/batch/{Stepper,PhotoPicker,PhotoTile,BatchSettingsForm,BatchStatusBadge}.jsx`
- `resources/js/pages/company/{BatchNew,BatchEdit,BatchDetail}.jsx`
- `tests/Feature/BatchUploadTest.php`, `tests/Unit/ImageTypeAndNamingTest.php`

### Gewijzigd

`app/Models/Batch.php` (relatie `cover`), `app/Providers/AppServiceProvider.php` (rate limiter `uploads`), `routes/api.php`, `lang/{nl,en}/messages.php`, `resources/js/App.jsx`, `resources/js/pages/company/Dashboard.jsx`, `resources/js/locales/{nl,en}.json`, `docs/PLESK.md`.

### Handmatig testen

1. Log in als `demo@example.com` en klik **Foto's optimaliseren**.
2. **Desktop:** kies meerdere JPG/PNG-bestanden en sleep er ook een paar in het vlak. Thumbnails, bestandsgrootte en uploadvoortgang verschijnen per foto.
3. **iPhone (Safari):** "Foto's kiezen" opent de fotobibliotheek met meervoudige selectie; "Camera" opent direct de camera. Kies ook een HEIC-foto (Instellingen > Camera > Formaten > Hoge efficiëntie).
4. **Android (Chrome):** zelfde test; de cameraknop opent de camera.
5. **Foutmeldingen:** een PDF of `.txt` hernoemd naar `.jpg` (ongeldig bestand), een bestand groter dan de limiet (te groot), meer dan 30 foto's in één keer (melding hoeveel er niet zijn toegevoegd), een half gedownloade JPEG (beschadigd bestand).
6. Foto verwijderen uit het concept; mislukte upload opnieuw proberen.
7. Pagina verlaten tijdens uploaden geeft een waarschuwing; na terugkomen via het dashboard staat het concept er nog.
8. **Stap 2:** batchnaam "BMW 320i" toont het voorbeeld `bmw-320i-01.jpg`; PNG kiezen verandert de extensie. Watermark-opties staan uit zolang er geen logo is.
9. **Starten:** je komt op de batchpagina met "0 van N klaar", status Wachten en de foto-cards. Terug naar het concept is niet meer mogelijk.
10. **Tenant-isolatie:** open als ander bedrijf de URL van een batch of foto van de demo: 403.
11. Batch verwijderen: bestanden verdwijnen en het opslaggebruik op het dashboard daalt.

### PHP-instellingen voor uploads (Plesk)

`upload_max_filesize` en `post_max_size` minimaal 1 MB hoger dan de ingestelde maximale bestandsgrootte (standaard 25 MB, dus bijvoorbeeld 32M / 34M). Aangevuld in `docs/PLESK.md`.

## Fase 3: Bestandsopslag, HEIC/HEIF-conversie en normale beeldverwerking

**Status:** afgerond. `php artisan test`: 61 tests, 364 assertions, alles groen. React-build slaagt.

### Keuzes

- **Opslagstructuur** per batch op de private disk:
  `companies/{company}/batches/{batch}/{original,working,thumbs,optimized}/{ulid}.{ext}`. Verwijderen van een batch = één map weg.
- **HEIC/HEIF in drie lagen:**
  1. De browser zet HEIC om naar JPEG (`heic-to`, libheif in WebAssembly, ~750 KB gzip, alleen geladen als er HEIC wordt gekozen). Eén conversie tegelijk om het geheugen van telefoons te sparen.
  2. Lukt dat niet, dan probeert de browser zijn eigen decoder (Safari).
  3. Lukt dat ook niet, dan gaat het origineel naar de server, die Imagick (met HEIC) of een CLI-tool (`heif-convert`, `magick`) probeert. Zonder die tools krijgt de gebruiker direct bij de upload de melding "conversie mislukt", met een tip ("Meest compatibel" op de iPhone).
- **Voorbereiding direct na upload** (`ImagePreparer`): EXIF-oriëntatie toepassen, werkkopie (max 3072 px, JPEG 92 %, zonder metadata), thumbnail (480 px), lokale kwaliteitsmeting en perceptuele hash. Kapotte bestanden en mislukte conversies worden zo meteen gemeld in plaats van minuten later.
- **Alles met GD**, dat op iedere hosting aanwezig is. Imagick is optioneel en wordt alleen voor HEIC gebruikt. De EXIF-oriëntatie wordt zelf gelezen, zodat de `exif`-extensie niet verplicht is.
- **Geheugenbewaking:** voor het openen wordt berekend hoeveel geheugen nodig is; waar mogelijk wordt `memory_limit` tijdelijk verhoogd, anders volgt een nette melding in plaats van een fatale fout.
- **Metadata:** GD schrijft geen EXIF/GPS/XMP; iedere werkkopie, thumbnail en eindresultaat is dus schoon. Het origineel blijft ongewijzigd bewaard (voor de voor/na-vergelijking) en wordt na de bewaartermijn verwijderd.
- **Uitvoer** (`OutputRenderer`): bijsnijden naar de gekozen verhouding, verkleinen naar de gekozen resolutie (nooit vergroten), JPG met de ingestelde kwaliteit (85 tot 90 %) of PNG met transparantie. Bestandsnaam `bmw-320i-01.jpg`. Een nieuwe versie vervangt de vorige.
- **Slim bijsnijden:** met een productkader (komt uit de AI-analyse in fase 5) blijft het hele product altijd in beeld; past het product niet in de verhouding, dan wordt het canvas aangevuld met de randkleur in plaats van het product af te snijden. Zonder kader kiest het algoritme de uitsnede met de meeste details.
- **Lokale verbetering zonder AI** (`LocalEnhancer`): belichting (gamma), contrast, lichte verscherping en alleen een witbalanscorrectie als er genoeg neutrale (grijze) pixels zijn. Een rode auto wordt dus nooit "gecorrigeerd" naar een andere kleur. Wordt gebruikt als AI uitstaat of als een AI-bewerking mislukt.
- **Waarschuwingen zonder AI:** te donker, sterk overbelicht, weinig contrast, mogelijk onscherp/bewogen. De AI verfijnt dit in fase 5.
- **Dubbele foto's** bij het starten van een batch: identiek (zelfde bestand) of sterk gelijkend (perceptuele hash). De latere foto krijgt "Deze afbeelding lijkt sterk op foto 7"; er wordt niets verwijderd.
- **Verwerking per foto** (`ImagePipeline`) met de statussen Analyseren, Optimaliseren, Afronden, Klaar/Mislukt. Een fout bij één foto raakt de andere niet. De batchstatus (`BatchProgress`) wordt na iedere foto opnieuw bepaald. In fase 4 draait dit via de queue; nu kan het handmatig met `php artisan bora:process-local`.

### Toegevoegd

- `app/Services/Images/{ImageEditor,ExifOrientation,MemoryGuard,HeicConverter,ImagePreparer,LocalEnhancer,PerceptualHash,DuplicateDetector,OutputRenderer}.php`
- `app/Services/Processing/{ImagePipeline,BatchProgress}.php`
- `app/Services/Storage/LocalFiles.php` (lokale paden voor GD, klaar voor object storage)
- `app/Console/Commands/ProcessBatchLocally.php` (`php artisan bora:process-local {batch?}`)
- `resources/js/lib/heic.js`
- `tests/Feature/ImageProcessingTest.php`

### Gewijzigd

`app/Services/Images/ImageUploadService.php` (voorbereiding na upload, opruimen bij fout), `app/Services/BatchService.php` (dubbele foto's bij starten), `app/Http/Resources/ImageResource.php` (vertaalde waarschuwingen, uitvoermaten), `config/bora.php` (sectie `processing`), `lang/{nl,en}/messages.php`, `resources/js/lib/useUploadQueue.js` (HEIC-conversie, geen herhaalknop bij definitieve fouten), `resources/js/components/batch/PhotoTile.jsx`, `resources/js/locales/{nl,en}.json`, `package.json` (`heic-to`), `tests/Feature/BatchUploadTest.php`.

### Handmatig testen

1. **iPhone HEIC:** zet de camera op "Hoge efficiëntie", kies een paar foto's. De tegel toont "HEIC omzetten…", daarna een echte preview en de upload.
2. **HEIC op desktop Chrome/Firefox:** zelfde test met een HEIC-bestand van de computer.
3. **Staande foto** van een telefoon: de thumbnail staat rechtop (EXIF-oriëntatie).
4. **Heel donkere foto** en een **bewogen foto**: de tegel toont de waarschuwing.
5. **Twee keer dezelfde foto** en een licht bijgesneden kopie in één batch; na starten staat bij de kopieën "lijkt sterk op foto N".
6. Start de batch en draai `php artisan bora:process-local`. De batchpagina (polling) laat de foto's klaar komen, met percentage en geslaagd/mislukt.
7. Download het resultaat via de URL `/api/company/images/{id}/optimized` en controleer: juiste verhouding en resolutie, geen EXIF/GPS (bijv. met `exiftool`), bestandsnaam volgt in fase 7.
8. Kies PNG en 16:9 en verwerk opnieuw: PNG met de juiste afmetingen.
9. **Server zonder HEIC-ondersteuning:** met een browser die de conversie niet kan (of door de netwerkaanvraag van `heic-to` te blokkeren in de DevTools) krijg je de melding "conversie mislukt".

### Plesk

Geen nieuwe verplichtingen: GD volstaat. Optioneel voor extra HEIC-zekerheid: Imagick met HEIC-ondersteuning, of pad naar `heif-convert` via `BORA_HEIC_BINARIES`.

## Fase 4: Queues en batchverwerking

**Status:** afgerond. `php artisan test`: 71 tests, 439 assertions, alles groen. React-build slaagt. End-to-end getest: 3 foto's uploaden, starten, cron-URL aanroepen, batch klaar met `foto-01.jpg` tot en met `foto-03.jpg`.

### Keuzes

- **Eén job per foto** (`ProcessImage`) op de queue `images`, verstuurd na het committen van de batchstart. Uniek per foto, dus nooit dubbel tegelijk.
- **Geen permanente worker:** `bora:work` verwerkt maximaal ~50 seconden (`--stop-when-empty`, `--max-time`) en stopt. Een cache-lock zorgt dat er nooit twee workers tegelijk draaien, ook als cronruns overlappen. Eén foto tegelijk: veilig voor shared hosting.
- **Eén cronjob** start `schedule:run`; de scheduler start `bora:work` (iedere minuut), de watchdog (iedere 10 minuten) en het opschonen van oude mislukte jobs (dagelijks). In fase 8 komt de dagelijkse cleanup erbij.
- **Zonder SSH:** Plesk "PHP-script uitvoeren" (`artisan schedule:run`) of, als dat niet kan, "URL ophalen" op `/cron/{BORA_CRON_TOKEN}`. De URL staat uit zonder sleutel, vergelijkt de sleutel veilig en heeft rate limiting.
- **Retries:** 3 pogingen met oplopende wachttijd (30 s, 2 min, 5 min) bij onverwachte, mogelijk tijdelijke fouten. Regelovertredingen (kapot bestand, conversie mislukt) worden niet herhaald. Na de laatste poging wordt alleen die foto "Mislukt", met een begrijpelijke melding; de rest van de batch gaat door.
- **Vastgelopen jobs:** een worker die halverwege stopt, geeft zijn job na `retry_after` (300 s) automatisch terug. De watchdog `bora:recover-stuck` zet foto's zonder voortgang (20 minuten) opnieuw in de wachtrij of markeert ze als mislukt als alle pogingen op zijn.
- **Gezondheid in beeld:** Super Admin > Overzicht toont de wachtrij (wachtende taken, oudste taak, laatste verwerking, mislukte taken) en waarschuwt als er foto's wachten maar de cronjob niet draait.
- **Later naar een VPS:** `QUEUE_CONNECTION=redis` en een permanente `queue:work --queue=images,default` onder supervisor; de code hoeft niet te veranderen.

### Toegevoegd

- `app/Jobs/ProcessImage.php`
- `app/Console/Commands/{Work,RecoverStuckImages}.php` (`bora:work`, `bora:recover-stuck`)
- `app/Services/Processing/QueueHealth.php`
- `app/Http/Controllers/CronController.php` (`GET /cron/{token}`)
- `tests/Feature/QueueProcessingTest.php`

### Gewijzigd

`app/Services/BatchService.php` (jobs na start), `app/Services/Processing/ImagePipeline.php` (retry-logica, `failPermanently`), `routes/console.php` (scheduler), `bootstrap/app.php` (cron-route), `routes/web.php`, `app/Providers/AppServiceProvider.php` (rate limiter `cron`), `app/Http/Controllers/Api/Admin/SystemController.php` (wachtrijstatus), `config/queue.php` (`retry_after`, `after_commit`), `config/bora.php` (sectie `queue`), `.env.example`, `resources/js/pages/admin/AdminDashboard.jsx`, `resources/js/locales/{nl,en}.json`, `tests/Feature/{BatchUploadTest,ImageProcessingTest}.php`, `docs/PLESK.md`.

### Handmatig testen

1. **Lokaal:** start een batch en draai `php artisan schedule:run` (of `php artisan bora:work`). De batchpagina toont de foto's één voor één als klaar, met "x van N klaar" en percentage.
2. **Cron-URL lokaal:** zet `BORA_CRON_TOKEN` in `.env` en open `http://localhost:8000/cron/<token>`: antwoord `OK` en de batch wordt verwerkt. Een verkeerde sleutel geeft 404.
3. **Op Plesk:** maak de geplande taak (optie A of B in `docs/PLESK.md`), start een batch met 10 foto's en laat het tabblad open: afgeronde foto's verschijnen zonder te verversen.
4. **Super Admin > Overzicht:** de kaart "Wachtrij" toont "Bezig" tijdens verwerking en daarna "Rustig". Zet de geplande taak uit en start een batch: na een paar minuten verschijnt "Wacht op cronjob".
5. **Weggaan tijdens verwerking:** sluit de browser, kom na een paar minuten terug: de batch is verder of klaar.
6. **Batch verwijderen tijdens verwerking:** de resterende jobs worden stil overgeslagen, zonder mislukte taken.

## Fase 5: OpenAI-analyse en fotobewerking

**Status:** afgerond. `php artisan test`: 86 tests, 548 assertions, alles groen (OpenAI gesimuleerd met `Http::fake`). React-build slaagt.

### Modellen (in te stellen via `.env`)

- **Analyse en controle:** `gpt-5.4-mini` via de Responses API, met beeldinvoer en Structured Outputs (strikt JSON-schema).
- **Bewerking:** `gpt-image-2` via `/v1/images/edits`.
- Wisselen kan zonder codewijziging: `OPENAI_ANALYSIS_MODEL`, `OPENAI_IMAGE_MODEL`. Nieuwere modellen zoals `gpt-image-2.5-flare` of `gpt-image-2.5-sunburst` werken met dezelfde code; de kostenschatting staat in `config/services.php`.

### Hoe een foto wordt verwerkt

1. **Analyseren** (één keer per foto, opgeslagen en hergebruikt bij retries en opnieuw optimaliseren): productomschrijving, productkader (voor slim bijsnijden), problemen (donker, licht, onscherp, bewogen, ruis, witbalans, contrast, lage kwaliteit, scheef, storende achtergrond, harde schaduwen), herstelbaar ja/nee, personen (en of ze voor het product staan), zichtbare kentekens/teksten/beschadigingen, voorgestelde correcties en of een generatieve bewerking nodig is.
2. **Beslissen** (`OPENAI_EDIT_POLICY=auto`): een generatieve bewerking alleen als die echt nodig is:
   - altijd bij een achtergrondoptie of "personen verwijderen" (als er personen zijn);
   - bij **Sterk** altijd, bij **Normaal** alleen als de analyse het aanraadt, bij **Subtiel** nooit;
   - nooit bij een foto die niet betrouwbaar te herstellen is (anders zou de AI details kunnen verzinnen).

   Zonder bewerking worden de correcties van de AI lokaal toegepast (belichting, contrast, witbalans, ruis, verscherping, lichte rechtzetting): geen risico voor het product en veel goedkoper.
3. **Bewerken:** de prompt (`EditInstructionBuilder`) begint altijd met de vaste productintegriteitsregels (product exact behouden, beschadigingen zichtbaar, kleur, tekst, logo's, kenteken, niets toevoegen of verwijderen, niets reconstrueren zonder informatie, alleen presentatie en beeldkwaliteit), daarna de specifieke punten uit de analyse (bijvoorbeeld "kras op achterbumper moet blijven") en de gekozen achtergrond- en personenopties. Het formaat volgt de verhouding van de foto (veelvoud van 16, max 2048 px).
4. **Controleren** (`OPENAI_VERIFY_EDITS=true`): een tweede analyse vergelijkt origineel en resultaat. Is het product veranderd (vorm, onderdelen, kleur, beschadiging, tekst, logo's, kenteken, kunstmatig uiterlijk), dan wordt de bewerking **afgekeurd** en krijgt de foto de veilige lokale correcties, met een melding.
5. **Afronden:** bijsnijden met het productkader, resolutie, formaat, zonder metadata (fase 3).

### Fouten en kosten

- **Binnen één job:** 429 en 5xx en time-outs worden 2 keer opnieuw geprobeerd (2 s en 6 s).
- **Daarna de queue:** tot 3 pogingen met oplopende wachttijd.
- **Blijft OpenAI onbereikbaar, geen tegoed, of weigert het model:** de foto krijgt toch lokale basiscorrecties en de melding "AI-verwerking was niet beschikbaar". Een foto blijft nooit hangen op OpenAI.
- Iedere OpenAI-aanroep wordt vastgelegd met model, tokens, duur en geschatte kosten (zichtbaar voor de Super Admin per bedrijf en platformbreed).
- De API-key staat alleen in `.env`, wordt alleen als `Authorization`-header meegestuurd en wordt nooit gelogd; foto's en prompts worden ook niet gelogd.
- Een geslaagde bewerking wordt bewaard (`images.ai_path`), zodat een herhaalde job niet opnieuw betaalt.

### Nieuwe meldingen bij foto's

Sterk bewogen, veel ruis, lage kwaliteit (alleen als herstel beperkt is), persoon staat deels voor het product, niet alle personen verwijderd, AI-bewerking afgekeurd, AI niet beschikbaar. Op iedere klaar-foto staat hoe hij verwerkt is (bijvoorbeeld "AI-bewerkt en gecontroleerd").

### Toegevoegd

- `app/Services/OpenAI/{OpenAIClient,OpenAIException,ImageInput,Usage,ImageAnalyzer,EditInstructionBuilder,ImageEditService,EditVerifier,AiImageProcessor}.php`
- `app/Enums/AiStatus.php`
- `database/migrations/2026_09_28_000400_add_ai_path_to_images_table.php`
- `tests/Feature/OpenAIProcessingTest.php`

### Gewijzigd

`app/Services/Processing/ImagePipeline.php` (AI-stappen, terugval), `app/Services/Images/ImageEditor.php` (ruisonderdrukking, rechtzetten), `app/Services/Images/OutputRenderer.php`, `app/Services/CompanyStatsService.php` (telling per afgeronde foto), `app/Http/Resources/ImageResource.php` (`ai_status`), `app/Http/Controllers/Api/Admin/SystemController.php` (OpenAI-info zonder key), `config/services.php` (sectie `openai`), `config/bora.php` en `config/queue.php` (langere job-timeout voor AI), `lang/{nl,en}/messages.php`, `resources/js/pages/admin/SystemSettings.jsx`, `resources/js/components/batch/PhotoTile.jsx`, `resources/js/locales/{nl,en}.json`, `.env.example`, `docs/PLESK.md`.

### Handmatig testen (met een echte key)

1. Zet `OPENAI_API_KEY` in `.env`, `php artisan config:clear`. Super Admin > Systeem toont "API-key: Ingesteld".
2. **Subtiel, achtergrond behouden:** batch met 3 foto's. Resultaat: "AI-analyse, veilige correcties", en in Super Admin 3 AI-verzoeken.
3. **Sterk:** auto met zichtbare kras en kenteken. Controleer in het resultaat: kras nog zichtbaar, kenteken identiek, kleur gelijk.
4. **Neutrale achtergrond** en **achtergrond verwijderen** (met PNG: transparant; met JPG: wit).
5. **Personen verwijderen:** foto met voorbijganger op de achtergrond; en een foto waar iemand deels voor het product staat (melding).
6. **Bewogen of heel donkere foto:** melding "Verbetering is beperkt mogelijk" en geen generatieve bewerking.
7. **Ongeldige key:** zet een foute key; foto's worden toch klaar met "Basiscorrecties (AI niet beschikbaar)".
8. **AI uitzetten** in Super Admin > Systeem: er gaan geen verzoeken meer naar OpenAI.
9. Super Admin > Overzicht en bij een bedrijf: AI-verzoeken en geschatte kosten lopen op.

## Fase 6: Voor/na-vergelijking en opnieuw optimaliseren

**Status:** afgerond. `php artisan test`: 93 tests, 606 assertions, alles groen. React-build slaagt.

### Keuzes

- **Voor/na-slider** (`CompareSlider`): een echte range-input over twee lagen, dus slepen met muis of vinger en bedienen met de pijltjestoetsen. Labels "Voor" en "Na". Daarnaast de losse weergaven **Origineel** en **Geoptimaliseerd**.
- **"Voor" is de werkkopie:** rechtgezet volgens EXIF, zonder metadata, en ook zichtbaar bij HEIC-originelen (die de meeste browsers niet tonen). Nieuwe bestandsvariant `working` via dezelfde beveiligde route.
- **Foto-viewer** (`ImageViewer`): schermvullend op mobiel, groot venster op desktop. Toont afmetingen, bestandsgrootte, meldingen en de knop "Opnieuw optimaliseren".
- **Opnieuw optimaliseren** (`POST /api/company/images/{image}/reoptimize`): kies sterkte, achtergrond en personen opnieuw. Werkt voor klare en mislukte foto's; een foto die nog bezig is geeft `image_busy`.
  - De AI-analyse wordt hergebruikt (geen tweede analyse, geen extra kosten).
  - Een eerdere AI-bewerking wordt weggegooid, omdat die met andere instellingen is gemaakt.
  - De huidige versie blijft zichtbaar tot de nieuwe klaar is en wordt dan vervangen; oude varianten worden niet bewaard.
  - De batch wordt weer actief, dus de pagina pollt vanzelf en de foto verschijnt opnieuw als hij klaar is.
  - Het verbruik wordt apart geregistreerd als `reoptimize`.
- Foto-cards tonen bij klare en mislukte foto's de knoppen **Voor/na** en **Opnieuw**; klikken op de thumbnail opent de viewer.

### Toegevoegd

- `app/Services/Processing/ReoptimizeService.php`
- `app/Http/Requests/Batch/ReoptimizeImageRequest.php`
- `resources/js/components/batch/{CompareSlider,ImageViewer}.jsx`
- `tests/Feature/ReoptimizeTest.php`

### Gewijzigd

`app/Http/Controllers/Api/Company/ImageController.php` (actie `reoptimize`, variant `working`), `routes/api.php`, `app/Http/Resources/{ImageResource,BatchResource}.php` (`urls.before`, `settings`, `reoptimized`), `app/Services/Processing/ImagePipeline.php` (type `reoptimize`), `lang/{nl,en}/messages.php`, `resources/js/components/batch/PhotoTile.jsx`, `resources/js/pages/company/BatchDetail.jsx`, `resources/js/locales/{nl,en}.json`, `tests/Feature/OpenAIProcessingTest.php`.

### Handmatig testen

1. Open een klare batch en tik op een foto: de viewer opent met de voor/na-slider. Sleep met je vinger (iPhone/Android) en met de muis; test ook de pijltjestoetsen.
2. Wissel tussen **Voor/na**, **Origineel** en **Geoptimaliseerd**.
3. Staande telefoonfoto en HEIC-foto: "Voor" staat rechtop en is zichtbaar.
4. **Opnieuw optimaliseren** met Sterk en neutrale achtergrond: de foto gaat naar "Wachten", de batch wordt weer actief en de nieuwe versie verschijnt vanzelf. De oude versie is weg.
5. Een mislukte foto opnieuw proberen via **Opnieuw**.
6. Met een echte OpenAI-key: in Super Admin zie je voor de nieuwe versie een bewerking en een controle, maar geen tweede analyse.

## Fase 7: Watermark, logo's en downloads

**Status:** afgerond. `php artisan test`: 102 tests, 686 assertions, alles groen. React-build slaagt.

### Keuzes

- **Bedrijfslogo** (Instellingen > Bedrijfslogo): uploaden, vervangen, verwijderen. PNG of JPG (op inhoud gecontroleerd), maximaal 5 MB; opgeslagen als PNG met transparantie, maximaal 1200 px, zonder metadata, op de private disk. Alleen het eigen bedrijf kan het ophalen (`GET /api/company/logo`, zonder bedrijfs-id in de URL). Wijzigingen worden gelogd (`logo_changed`) en tellen mee in het opslaggebruik. Het dashboard toont het logo.
- **Watermark alleen bij downloaden** (`WatermarkRenderer`): het opgeslagen resultaat blijft altijd zonder logo. Daardoor kun je de watermark op ieder moment aan- of uitzetten, verplaatsen of doorzichtiger maken, zonder opnieuw te verwerken.
  - Keuzes: geen, alle foto's, of geselecteerde foto's (vinkje "Logo op deze foto" per foto).
  - Posities: linksboven, rechtsboven, linksonder, rechtsonder, midden. Transparantie 10 tot 100 %.
  - Logo op 18 % van de fotobreedte (max 25 % van de hoogte), marge 3 %, transparantie van het logo blijft behouden.
- **Downloads** via beveiligde routes (Policy `download`, alleen eigen bedrijf):
  - Los: `GET /api/company/images/{image}/download`, met de bestandsnaam `bmw-320i-01.jpg` en zonder EXIF/GPS.
  - ZIP: `GET /api/company/batches/{batch}/download` als `bmw-320i.zip`. Wordt pas gemaakt als iemand erom vraagt, zonder hercompressie (snel), en hergebruikt tot een foto of de watermark verandert; dan wordt de oude ZIP vervangen.
- **Stap 5 op de batchpagina** (`DownloadPanel`): "Alles downloaden (ZIP)" en de watermarkinstellingen. Per foto een downloadknop op de card en in de viewer.

### API (nieuw)

`GET/POST/DELETE /api/company/logo`, `PUT /api/company/batches/{batch}/watermark`, `PATCH /api/company/images/{image}/watermark`, `GET /api/company/images/{image}/download`, `GET /api/company/batches/{batch}/download`. Nieuwe foutcodes: `logo_invalid`, `nothing_to_download`.

### Toegevoegd

- `app/Services/{LogoService,DownloadService}.php`, `app/Services/Images/WatermarkRenderer.php`
- `app/Http/Controllers/Api/Company/{LogoController,DownloadController}.php`
- `resources/js/components/company/LogoCard.jsx`, `resources/js/components/batch/DownloadPanel.jsx`
- `tests/Feature/DownloadAndWatermarkTest.php`

### Gewijzigd

`app/Services/Storage/StorageAccounting.php` (opslag op bedrijfsniveau), `app/Http/Resources/{UserResource,CompanySettingsResource,ImageResource,BatchResource}.php` (logo- en download-URL's), `routes/api.php`, `lang/{nl,en}/messages.php`, `resources/js/components/batch/{PhotoTile,ImageViewer}.jsx`, `resources/js/pages/company/{BatchDetail,Settings,Dashboard}.jsx`, `resources/js/locales/{nl,en}.json`.

### Handmatig testen

1. Instellingen: upload een PNG met transparante achtergrond; vervang het door een JPG; verwijder het. Het dashboard toont het logo.
2. Een ongeldig bestand (bijv. GIF of PDF hernoemd naar .png) geeft een duidelijke melding.
3. Batchpagina van een klare batch: kies "Logo op alle foto's", rechtsonder, 70 %. Download één foto: logo zichtbaar. Open de foto in de viewer: het resultaat daar is zonder logo.
4. Kies "Logo op geselecteerde foto's", vink twee foto's aan en download de ZIP: alleen die twee hebben het logo.
5. ZIP: bestandsnamen `batchnaam-01.jpg` enzovoort; tweede keer downloaden is direct klaar; na een watermarkwijziging wordt een nieuwe ZIP gemaakt.
6. Download op iPhone (Safari) en Android: losse foto opent/bewaart, ZIP wordt gedownload.
7. Controleer een gedownloade foto met `exiftool`: geen EXIF of GPS.
8. Als ander bedrijf de download-URL openen: 403.

## Fase 8: Geschiedenis, cleanup en e-mail

**Status:** afgerond. `php artisan test`: 108 tests, 756 assertions, alles groen. React-build slaagt.

### Keuzes

- **Mijn verwerkingen** (`/history`, in het menu): cards in plaats van een tabel (mobielvriendelijk) met thumbnail, naam, datum, aantal foto's, status, verwerkingsinstellingen, "beschikbaar tot", en de knoppen Openen, ZIP en Verwijderen. Pagina's van 12; ververst zichzelf zolang er een batch bezig is. Het dashboard linkt ernaar.
- **Bewaartermijn:** iedere batch krijgt `expires_at` = start + bewaartermijn. Past de Super Admin de termijn aan, dan worden de datums van bestaande batches meteen herberekend. Verlopen batches zijn direct onzichtbaar, ook als de cleanup nog niet gedraaid heeft.
- **Dagelijkse cleanup** (`php artisan bora:cleanup`, 03:15 via de scheduler, ook `--dry-run`): verwijdert per verlopen batch het origineel, de werkkopie, AI-resultaten, geoptimaliseerde foto's, thumbnails, ZIP en de database-rijen; daarnaast lege concepten ouder dan een dag, tijdelijke bestanden ouder dan een dag en activiteitenlogs ouder dan een jaar. Een batch die nog bezig is, wordt pas een dag later verwijderd. Statistieken (`image_processing_records`, tellers per bedrijf) blijven bewaard, zonder koppeling naar foto's. Verlopen reset-tokens worden dagelijks opgeruimd (`auth:clear-resets`).
- **E-mail als de batch klaar is** (`BatchCompleted`): onderwerp met batchnaam, "Aantal succesvol", "Aantal met fout", knop "Bekijk resultaten", en tot wanneer de foto's beschikbaar zijn. Geen bijlagen. In de taal van de gebruiker. Precies één keer per batch (ook na "opnieuw optimaliseren" geen tweede mail); via de wachtrij, en een mailfout breekt de verwerking nooit.

### Toegevoegd

- `app/Console/Commands/Cleanup.php` (`bora:cleanup`)
- `app/Notifications/BatchCompleted.php`, `app/Services/Processing/BatchCompletionNotifier.php`
- `resources/js/pages/company/History.jsx`
- `tests/Feature/HistoryCleanupMailTest.php`

### Gewijzigd

`app/Services/Processing/BatchProgress.php` (mail bij afronden), `app/Http/Controllers/Api/Company/BatchController.php` (verlopen batches verbergen), `app/Http/Controllers/Api/Admin/SystemController.php` (datums herberekenen), `routes/console.php` (cleanup en reset-tokens), `lang/{nl,en}/mail.php`, `resources/js/App.jsx`, `resources/js/pages/company/Dashboard.jsx`, `resources/js/locales/{nl,en}.json`, `docs/PLESK.md` (mail en cleanup).

### Handmatig testen

1. Menu "Mijn verwerkingen": je batches staan er als cards, met ZIP-knop bij klare batches; verwijderen vraagt om bevestiging.
2. Verwerk een batch met `MAIL_MAILER=log`: in `storage/logs/laravel-*.log` staat één mail met de aantallen en de knop. Op Plesk met SMTP: controleer de mail in je inbox (ook spam).
3. "Opnieuw optimaliseren" op een foto in die batch: geen tweede mail.
4. Super Admin > Systeem: zet de bewaartermijn op 1 dag; de datum "beschikbaar tot" van batches verschuift direct.
5. `php artisan bora:cleanup --dry-run` toont wat er weg zou gaan; zonder `--dry-run` verdwijnen verlopen batches en hun bestanden, en daalt het opslaggebruik. Het aantal verwerkte foto's in Super Admin blijft gelijk.

## Fase 9: Mobiele optimalisatie

**Status:** afgerond. `php artisan test`: 108 tests, 756 assertions, alles groen. React-build slaagt. Alle schermen (bedrijf en Super Admin, 18 in totaal) zijn nagelopen op 390 × 844 px (iPhone-formaat), met een automatische controle op horizontaal scrollen: nergens meer.

### Keuzes

- **Horizontaal scrollen opgelost** op de batchpagina na afronding: de actieknoppen onder een foto duwden de pagina 28 px te breed (en daarmee ook de fotoviewer). Foto-cards krimpen nu mee, en de acties zijn drie even brede touch-knoppen van 48 px hoog met icoon boven een kort label: Voor/na, Opnieuw, Download.
- **Voor/na-slider werkt overal op aanraking:** tikken of slepen op elk punt van de foto (pointer events). Voorheen reageerde iOS alleen op het onzichtbare schuifje zelf. De pijltjestoetsen blijven werken via de toegankelijke schuifregelaar.
- **Grote foto's worden in de browser verkleind** tot maximaal 4096 px aan de langste zijde (JPG, PNG en omgezette HEIC; ruim genoeg voor de grootste output van 2560 px). Minder uploadtijd over mobiel internet, minder schijfruimte en minder PHP-geheugen op shared hosting. Foto's kleiner dan 2,5 MB worden niet aangeraakt. EXIF-rotatie wordt daarbij toegepast en metadata (ook GPS) valt weg. Status bij de foto: "Verkleinen…". Lukt het de browser niet, dan gaat het origineel naar de server.
- **HEIC op iPhone:** de terugvaloptie via Safari tekent nu direct op het doelformaat. Een canvas van een 48 MP-foto is groter dan iOS toestaat en mislukte daardoor.
- **Scherm blijft aan tijdens het uploaden** (Screen Wake Lock, waar de browser het ondersteunt). Een vergrendelde telefoon pauzeert anders de uploads.
- **iPhone-randen:** kop, fotoviewer en de vaste knoppenbalk onderaan houden rekening met de notch en de home-balk (safe areas). De pagina achter een geopende viewer scrollt niet meer mee.
- **Kleiner:** de tabbladen in de viewer passen op smalle schermen, en de zoekplaceholder bij Bedrijven is ingekort.

Al aanwezig uit eerdere fases en gecontroleerd: grote uploadknoppen, knoppen voor fotobibliotheek en camera, meerdere foto's tegelijk selecteren, invoervelden met 16 px tekst (geen automatische zoom op iOS), cards in plaats van tabellen, polling die pauzeert als de app op de achtergrond staat.

### Toegevoegd

- `resources/js/lib/downscale.js` (verkleinen vóór upload)
- `resources/js/lib/useWakeLock.js`

### Gewijzigd

`resources/css/app.css` (safe areas, scroll-lock), `resources/js/components/batch/{PhotoTile,CompareSlider,ImageViewer}.jsx`, `resources/js/lib/{heic,useUploadQueue}.js`, `resources/js/layouts/AppLayout.jsx`, `resources/js/pages/company/BatchEdit.jsx`, `resources/js/locales/{nl,en}.json`.

### Handmatig testen (op een echte telefoon)

1. iPhone, Safari: kies 20 tot 30 foto's uit de fotobibliotheek (ook HEIC en een foto van 48 MP als je die hebt). Tegels tonen "HEIC omzetten…" of "Verkleinen…" en daarna de uploadvoortgang. Het scherm gaat niet uit tijdens het uploaden.
2. Knop Camera: maak een foto, die komt direct in de lijst.
3. Na de verwerking: geen horizontaal scrollen; de knoppen Voor/na, Opnieuw en Download zijn goed te raken met je duim.
4. Voor/na: tik ergens op de foto en sleep; de scheidslijn volgt je vinger. De pagina erachter scrollt niet mee.
5. iPhone met notch, liggend: de inhoud valt niet onder de notch; de knop "Verder naar instellingen" staat vrij van de home-balk.
6. Android (Chrome): dezelfde stappen 1 tot 4.

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

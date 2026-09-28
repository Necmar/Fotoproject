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

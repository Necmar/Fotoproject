# Bora Foto

Webapp waarmee bedrijven tot 30 productfoto's tegelijk uploaden en automatisch laten optimaliseren voor advertenties, webshops en verkoopplatformen. Iedere foto wordt afzonderlijk geanalyseerd en verbeterd, zonder eigenschappen van het product te veranderen.

- **Backend:** Laravel 13 (PHP 8.3+), JSON API met sessie-cookies en CSRF
- **Frontend:** React 19 (Vite, React Router, i18next, Tailwind CSS 4)
- **Database:** MySQL / MariaDB (lokaal SQLite)
- **Hosting:** Cloud86 shared hosting via Plesk, zonder permanente worker, Node.js-proces, Redis of Docker
- **Queue:** Laravel database queue, verwerkt door een Plesk-cronjob

## Lokaal starten

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed          # maakt lokaal ook demo@example.com / demo-wachtwoord-1
php artisan bora:super-admin        # centraal Super Admin-account
npm install
composer dev                        # php artisan serve + vite
```

Open http://localhost:8000. E-mails komen lokaal in `storage/logs/laravel-*.log` terecht (`MAIL_MAILER=log`).

Tests: `php artisan test`. Codestijl: `./vendor/bin/pint`.

## Structuur

```
app/
  Enums/                 statussen en keuzes (geen losse magic strings)
  Http/Controllers/Api/  dunne controllers: Auth, Account, Company, Admin
  Http/Requests/         Form Requests (validatie)
  Http/Resources/        consistente JSON-responses
  Http/Middleware/       super_admin, company, account.active, SetLocale
  Policies/              tenant-isolatie (Company, Batch, Image)
  Services/              businesslogica (CompanyService, SystemSettings, ActivityLogger, ...)
config/bora.php          app-configuratie en limieten
lang/{nl,en}/            Laravel-vertalingen
resources/js/            React-app (pages, layouts, components, locales)
routes/api.php           /api/* (web-middleware: sessie + CSRF)
routes/web.php           e-mailverificatie + SPA catch-all
docs/                    fase-rapporten en installatie
```

### API-conventies

- Succes: `{ "data": ..., "meta": ... }` (lijsten zijn gepagineerd met `meta` en `links`).
- Actie zonder resource: `{ "message": "..." }`.
- Validatiefout (422): `{ "message": "...", "errors": { "veld": ["..."] } }`.
- Overige fouten: `{ "message": "...", "code"?: "account_blocked" | "unauthenticated" }`. In productie nooit stack traces.

### Multi-tenancy

Eén database; alle bedrijfsdata heeft een `company_id`. Toegang wordt altijd server-side gecontroleerd met Policies en middleware. De Super Admin heeft geen `company_id` en kan de bedrijfsroutes niet gebruiken.

## Documentatie

- [docs/PHASES.md](docs/PHASES.md): per fase welke bestanden zijn toegevoegd of gewijzigd en wat getest moet worden
- [docs/PLESK.md](docs/PLESK.md): installatie op Cloud86/Plesk (wordt in fase 10 afgerond)

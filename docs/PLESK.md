# Installatie op Cloud86 / Plesk

> Werkversie na fase 1. Queue-cron, scheduler, OpenAI, upload-limieten en de volledige productiechecklist worden in fase 4, 5, 8 en 10 aangevuld.

## Vereisten

- PHP **8.3 of 8.4** (Plesk > Domein > PHP-instellingen)
- PHP-extensies: `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`, `fileinfo`, `gd`, `zip`, `exif`, `intl`
- Optioneel: `imagick` (betere HEIC-ondersteuning; zonder imagick converteert de browser HEIC naar JPEG)
- MySQL 8 of MariaDB 10.6+

## PHP-instellingen (Plesk > PHP-instellingen)

| Instelling | Waarde | Waarom |
|---|---|---|
| `upload_max_filesize` | `32M` | Eén foto per request; hoger dan "Maximale bestandsgrootte" in Systeeminstellingen (standaard 25 MB) |
| `post_max_size` | `34M` | Iets hoger dan `upload_max_filesize` |
| `max_file_uploads` | `20` (standaard is prima) | De app stuurt één bestand per request |
| `memory_limit` | `512M` | Beeldbewerking met GD van grote foto's (fase 3) |
| `max_execution_time` | `120` | Upload van grote bestanden op trage verbindingen |

## Eerste installatie

1. **Document root** van het domein instellen op de map `public` van het project.
2. Code plaatsen via Plesk Git (of upload) buiten de document root, bijvoorbeeld `/httpdocs/bora-foto`.
3. Database en databasegebruiker aanmaken in Plesk.
4. `.env` aanmaken op basis van `.env.example` en invullen:
   ```
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://jouwdomein.nl
   DB_CONNECTION=mysql
   DB_HOST=localhost
   DB_DATABASE=...
   DB_USERNAME=...
   DB_PASSWORD=...
   SESSION_SECURE_COOKIE=true
   MAIL_MAILER=smtp
   MAIL_HOST=... MAIL_PORT=587 MAIL_USERNAME=... MAIL_PASSWORD=... MAIL_FROM_ADDRESS=...
   ```
5. Via SSH (of Plesk "PHP Composer" + "Scheduled Tasks > Run a command"):
   ```bash
   composer install --no-dev --optimize-autoloader
   php artisan key:generate
   php artisan migrate --force
   php artisan bora:super-admin --email=beheer@jouwdomein.nl --name="Beheer" --password="..."
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```
6. **React-build:** er draait geen Node.js op de server. Bouw lokaal of in CI met `npm ci && npm run build` en upload `public/build/`. (Alternatief: als de Plesk Node.js-extensie beschikbaar is, kan `npm ci && npm run build` als deploy-actie draaien.)

## Updates

```bash
git pull   # of Plesk Git "Deploy"
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize
```
Upload daarna de nieuwe `public/build/`.

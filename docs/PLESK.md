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
5. Zonder SSH via Plesk "PHP Composer" en een geplande taak met "Nu uitvoeren" (zie hieronder), of via SSH:
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

## Cronjob voor de verwerking (zonder SSH)

Er draait geen permanente worker. Eén geplande taak in Plesk start iedere minuut de Laravel-scheduler; die verwerkt ongeveer 50 seconden foto's en stopt dan.

**Optie A (aanbevolen): PHP-script laten draaien**

Plesk > Websites & Domeinen > Geplande taken > Taak toevoegen:

| Veld | Waarde |
|---|---|
| Taaktype | PHP-script uitvoeren |
| Scriptpad | `httpdocs/bora-foto/artisan` (het `artisan`-bestand van het project) |
| Argumenten | `schedule:run` |
| PHP-versie | dezelfde als de website (8.3 of 8.4) |
| Uitvoeren | Cron-stijl `* * * * *` (iedere minuut) |
| Meldingen | Alleen bij fouten |

**Optie B: URL ophalen** (als "PHP-script uitvoeren" niet beschikbaar is)

1. Zet in `.env` een lange willekeurige sleutel (minimaal 24 tekens, alleen letters, cijfers, `-` en `_`):
   `BORA_CRON_TOKEN=...`
2. Geplande taak: Taaktype "URL ophalen", URL `https://jouwdomein.nl/cron/<sleutel>`, iedere minuut.

Zonder sleutel staat deze URL uit (404).

**Mag het niet iedere minuut?** Kies dan de kortste interval die kan (bijvoorbeeld iedere 5 minuten); verwerking duurt dan langer. Verhoog eventueel `BORA_WORKER_MAX_TIME` (seconden per run) tot maximaal de `max_execution_time` van PHP.

**Controle:** Super Admin > Overzicht > kaart "Wachtrij". Staat daar "Wacht op cronjob" terwijl er foto's klaarstaan, dan draait de geplande taak niet.

## E-mail

Nodig voor uitnodigingen, wachtwoordreset, e-mailverificatie en de melding "Je foto's zijn verwerkt". Gebruik een mailbox van het domein (Plesk > Mail):

```
MAIL_MAILER=smtp
MAIL_HOST=mail.jouwdomein.nl
MAIL_PORT=587
MAIL_SCHEME=null
MAIL_USERNAME=noreply@jouwdomein.nl
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=noreply@jouwdomein.nl
MAIL_FROM_NAME="Bora Foto"
```

De verwerkt-melding wordt via de wachtrij verstuurd (dezelfde cronjob). Stel SPF en DKIM in voor het domein (Plesk > Mail-instellingen) om spam-filters te voorkomen.

## Cleanup en bewaartermijn

Dezelfde cronjob (`schedule:run`) draait dagelijks om 03:15 `bora:cleanup`: batches voorbij de bewaartermijn (standaard 7 dagen, instelbaar in Super Admin > Systeem) worden met alle bestanden verwijderd, net als lege concepten en achtergebleven tijdelijke bestanden. Statistieken blijven bewaard. Handmatig testen: `bora:cleanup --dry-run` via een geplande taak met "Nu uitvoeren".

## OpenAI

Zet in `.env` op de server (nooit in de code of de frontend):

```
OPENAI_API_KEY=sk-...
OPENAI_ANALYSIS_MODEL=gpt-5.4-mini
OPENAI_IMAGE_MODEL=gpt-image-2
OPENAI_EDIT_POLICY=auto
OPENAI_VERIFY_EDITS=true
```

Controle: Super Admin > Systeem > kaart "OpenAI" toont of de key is ingesteld en welke modellen gebruikt worden. Na het wijzigen van `.env`: `php artisan config:cache` (via een geplande taak "Nu uitvoeren").

Uitgaande HTTPS-verbindingen naar `api.openai.com` moeten zijn toegestaan (standaard bij Cloud86).

## Artisan-commando's zonder SSH

Maak in Plesk een geplande taak van het type "PHP-script uitvoeren" met scriptpad `artisan` en het commando als argument, en klik op **Nu uitvoeren**. Bijvoorbeeld:

- `migrate --force` (na een update)
- `bora:super-admin --email=beheer@jouwdomein.nl --name=Beheer --password=...`
- `optimize` (config, routes en views cachen)

Composer: gebruik de Plesk-extensie **PHP Composer** (Websites & Domeinen > PHP Composer > Installeren).

## Updates

```bash
git pull   # of Plesk Git "Deploy"
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize
```
Upload daarna de nieuwe `public/build/`.

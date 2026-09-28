# Installatie op Cloud86 / Plesk

> Volledige handleiding (na fase 10). Na de installatie toont **Super Admin > Overzicht > Systeemcontrole** wat nog ontbreekt, met concreet advies.

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

Wat er op de server moet staan: de code, `vendor/` (PHP-pakketten) en `public/build/` (de gebouwde React-app). Er draait geen Node.js op de server, dus de React-build maak je op je eigen computer.

1. **Domein:** Plesk > Websites & Domeinen > Hostinginstellingen: zet de **document root** op de map `public` van het project (bijvoorbeeld `httpdocs/bora-foto/public`). Zet **SSL/TLS** aan (Let's Encrypt) en "Doorverwijzen van HTTP naar HTTPS".
2. **Code:** via Plesk Git (repository `Necmar/Fotoproject`, branch `main`, doelmap `httpdocs/bora-foto`) of door een zip te uploaden via Bestandsbeheer.
3. **PHP-pakketten:** Plesk > PHP Composer > project kiezen > **Installeren** (gebruikt `composer.lock`, zonder dev-pakketten). Alternatief: op je computer `composer install --no-dev --optimize-autoloader` en de map `vendor/` uploaden.
4. **React-build:** op je computer in de projectmap `npm ci` en `npm run build`, daarna de map `public/build/` uploaden naar `httpdocs/bora-foto/public/build/` (Bestandsbeheer: zip uploaden en uitpakken).
5. **Database:** Plesk > Databases > database en gebruiker aanmaken.
6. **.env:** kopieer `.env.production.example` naar `.env` en vul database, `APP_URL`, mail en `OPENAI_API_KEY` in. `APP_KEY` blijft leeg tot stap 8.
7. **Geplande taak** voor de cron aanmaken (zie hieronder). Dezelfde taak voert ook de update-stappen uit (migraties en caches, zie "Updates").
8. **Eenmalige commando's** via een geplande taak "PHP-script uitvoeren" met scriptpad `artisan` en **Nu uitvoeren** (zie "Artisan-commando's zonder SSH"), in deze volgorde:
   - `key:generate --force`
   - `migrate --force`
   - `bora:super-admin --email=beheer@jouwdomein.nl --name=Beheer --password=EenSterkWachtwoord123`
9. Log in als Super Admin en open **Overzicht > Systeemcontrole**. Los alles op wat rood of oranje is.

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

Controle: Super Admin > Systeem > kaart "OpenAI" toont of de key is ingesteld en welke modellen gebruikt worden. Wijzigingen in `.env` werken direct: de configuratie wordt bewust niet gecachet. (Heb je ooit zelf `config:cache` uitgevoerd, voer dan eenmalig `optimize:clear` uit.)

Uitgaande HTTPS-verbindingen naar `api.openai.com` moeten zijn toegestaan (standaard bij Cloud86).

## Artisan-commando's zonder SSH

Maak in Plesk een geplande taak van het type "PHP-script uitvoeren" met scriptpad `artisan` en het commando als argument, en klik op **Nu uitvoeren**. Bijvoorbeeld:

- `migrate --force` (na een update)
- `bora:super-admin --email=beheer@jouwdomein.nl --name=Beheer --password=...`
- `bora:doctor` (installatiecontrole, met de PHP-instellingen van de cron)
- `bora:deploy --force` (update-stappen opnieuw uitvoeren)

Composer: gebruik de Plesk-extensie **PHP Composer** (Websites & Domeinen > PHP Composer > Installeren).

## Updates

1. Nieuwe code op de server zetten: Plesk Git > **Pull/Deploy**, of uploaden.
2. Gewijzigd `composer.lock`? Plesk > PHP Composer > **Update/Installeren**.
3. Gewijzigde frontend? Lokaal `npm run build` en `public/build/` opnieuw uploaden (de oude map eerst leegmaken).
4. Klaar. Binnen een minuut ziet de cronjob dat de code veranderd is en voert `bora:deploy` automatisch uit: database-migraties, caches legen, routes/views/events opnieuw cachen. Resultaat: Systeemcontrole > "Laatste update". Mislukt er iets, dan staat de fout in `storage/logs/laravel-*.log`.

Open browsertabbladen met een oude versie laden zichzelf één keer opnieuw als ze een verdwenen bestand van de oude build nodig hebben.

## Beveiliging (productie)

- `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true` (zie `.env.production.example`). Met debug uit zien gebruikers nooit technische foutdetails; die staan in `storage/logs`.
- Alleen `public/` is bereikbaar via het web. `.env`, `storage/` en alle foto's staan erbuiten; foto's worden alleen via de app geleverd, na een eigenaarscontrole.
- Iedere response krijgt beveiligingsheaders: geen framing (clickjacking), `nosniff`, een strikte Referrer-Policy, HSTS op HTTPS en een Content Security Policy. Blokkeert de CSP onverwacht iets, zet dan tijdelijk `BORA_CSP=false` en meld het.
- Rate limits: inloggen (per account en per IP), wachtwoord vergeten, uploads, downloads, ZIP, cron-URL. "Opnieuw optimaliseren" kost een betaalde AI-bewerking en is begrensd: `BORA_REOPTIMIZE_PER_IMAGE` (standaard 5 per foto) en `BORA_REOPTIMIZE_PER_DAY` (standaard 300 per bedrijf per dag).
- Een nieuw wachtwoord (zelf of door de Super Admin) beëindigt de sessies en "ingelogd blijven" op andere apparaten. Een ander inlog-e-mailadres vraagt om het huidige wachtwoord.
- Achter de Plesk-proxy: `TRUSTED_PROXIES=*` is correct zolang Apache alleen via nginx bereikbaar is (standaard bij Plesk).
- Mail voor "wachtwoord vergeten" gaat via de wachtrij (zelfde cronjob): kan tot een minuut duren.

## Systeemcontrole

Super Admin > Overzicht > **Systeemcontrole** controleert: PHP-versie en extensies, `memory_limit`, uploadlimieten, uitvoertijd, applicatiesleutel, debugmodus, omgeving, HTTPS, veilige cookies, schrijfrechten, vrije schijfruimte, of de cronjob draait, e-mailinstelling, OpenAI-key, React-build en de laatste update. Alleen aandachtspunten worden getoond, met advies.

De website en de cron kunnen verschillende PHP-instellingen hebben. De kaart toont die van de website; `bora:doctor` via een geplande taak toont die van de cron.

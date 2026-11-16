# Kamelenrace

Dashboard voor lunch- en vergaderwandelingen op het werk. Elke collega heeft een eigen kameel
in de race; samen trekken we de karavaan naar de oase. Geen login: iedereen geeft op goed
vertrouwen zijn wandelingen op.

Gewone HTML-pagina (`public/index.html`) met een kleine PHP-API (`api/index.php`).
Draait op gewone webhosting (PHP + Apache), live op **hth.emmadriesse.com/kamelenrace**.

## Lokaal starten

```bash
KAMEEL_DATA_DIR=data php -S 127.0.0.1:8080 -t public dev-router.php
```

Open http://127.0.0.1:8080. Lokaal staat alles in `data/db.json`.

## Online zetten

Elke push naar `main` zet de site via FTP op de server (zie `.github/workflows/ftp-deploy.yml`).
Nodig in GitHub → Settings → Secrets and variables → Actions: `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`.

De data staat op de server in `/domains/hth.emmadriesse.com/kamelenrace-data/db.json`,
buiten `public_html`. Die wordt dus nooit door een deploy overschreven en is niet via de website
op te vragen. Maak er af en toe een kopie van als back-up.

## API

| Methode | Pad | Wat |
| --- | --- | --- |
| GET | `/api/state` | Alle kamelen, routes, wandelingen en doelen |
| POST | `/api/camels` | `{name, color}` kameel toevoegen |
| DELETE | `/api/camels/{id}` | kameel verwijderen (ook uit wandelingen) |
| POST | `/api/routes` | `{name, km}` vaste route toevoegen |
| DELETE | `/api/routes/{id}` | route verwijderen |
| POST | `/api/walks` | `{walkers: [id], km, kind: lunch\|vergader, date, routeId}` |
| DELETE | `/api/walks/{id}` | wandeling verwijderen |
| PUT | `/api/config` | `{teamGoal, personalGoal}` |

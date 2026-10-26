# SiteHub

Gestione dei siti statici di Plan Studios: clonazione da WordPress, moduli collegati ai siti (form con invio email via SMTP/Brevo), blog e pubblicazione su GitHub/Coolify.

- **Pannello e API** – `panel/` (PHP 8.4, SQLite, nessuna dipendenza) – online su `https://hub.planstudios.it`
- **Strumenti da riga di comando** – `clone.php` (copia fedele di un sito WordPress), `rebuild.php` (ricostruzione pulita)
- **Documentazione** – [`docs/DEPLOY.md`](docs/DEPLOY.md) messa online, [`docs/API.md`](docs/API.md) API per il gestionale, [`docs/INTEGRAZIONE.md`](docs/INTEGRAZIONE.md) come collegare l'HTML di un sito

## Sviluppo in locale

Serve solo Docker:

```bash
docker run --rm -p 8080:8080 -v "$PWD":/app -w /app php:8.4-cli php -S 0.0.0.0:8080 panel/public/index.php
docker run --rm -v "$PWD":/app -w /app php:8.4-cli php panel/bin/token.php create "Sviluppo"
```

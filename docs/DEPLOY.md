# Messa online con Coolify

Due tipi di applicazione su Coolify:

| | Repo | Cosa contiene |
|---|---|---|
| **Pannello** (una volta sola) | questo repo (`static-site-gen`) | API, moduli (form/email), pubblicazione del blog |
| **Un'applicazione per ogni sito** | un repo per sito (es. `alservice`) | il sito statico (`public/`) + `Dockerfile` e `nginx.conf` generati |

Entrambi usano il build pack **Dockerfile** e la porta **80**.

---

## A. Pannello (una volta sola)

1. **Repo:** pubblica questo repo su GitHub come repo **privato**.
2. **Coolify → New Resource → Application** dal repo, *Build Pack: Dockerfile*, porta 80, dominio es. `https://pannello.agenzia.it`.
3. **Environment Variables:**

   | Variabile | Valore |
   |---|---|
   | `PANEL_URL` | `https://pannello.agenzia.it` |
   | `PANEL_KEY` | output di `openssl rand -base64 32`. **Non cambiarla mai**: cifra le password SMTP |
   | `GITHUB_TOKEN` | token *fine-grained* con **Contents: Read and write** sui repo dei siti |
   | `TZ` | `Europe/Rome` |

4. **Persistent Storage:** volume montato su `/app/panel/data` (database). Senza volume, a ogni deploy si perde tutto.
5. **Scheduled Task:** `php /app/panel/bin/cron.php` ogni 5 minuti (`*/5 * * * *`). Riprova gli invii email non riusciti e pubblica gli articoli programmati.
6. **Deploy**, poi dal terminale dell'applicazione su Coolify crea il token dell'agenzia:
   ```
   php panel/bin/token.php create "Agenzia"
   ```
   Salvalo: non verrà più mostrato.

**Token GitHub:** se è limitato a repo specifici, ogni nuovo repo di un sito va aggiunto al token. In alternativa usa un token dell'organizzazione con accesso a tutti i repo.

---

## B. Un sito

Negli esempi: `API=https://pannello.agenzia.it/api/v1` e `TOKEN` = token agenzia.

### 1. Registra il sito nel pannello

```bash
curl -X POST $API/sites -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -d '{
  "slug": "alservice", "name": "Al Service", "domain": "https://alservice.it",
  "target": {"type": "github", "repo": "agenzia/alservice", "branch": "main"},
  "origins": ["alservice.agenzia.it"]
}'
```

La risposta contiene `embed.script_url`, cioè lo script del pannello con la chiave del sito. In `origins` va il dominio di prova, che altrimenti non potrebbe inviare i form.

### 2. Genera il sito collegato al pannello

```bash
php clone.php https://alservice.it
php rebuild.php sites/alservice --embed=https://pannello.agenzia.it/e/v1/pk_….js
```

Se usi la copia fedele invece della ricostruzione, aggiungi `--embed=…` direttamente a `clone.php`.

### 3. Crea il repo del sito e pubblica

```bash
cd sites/alservice-pulito
git init -b main && git add -A && git commit -m "Sito iniziale"
git remote add origin git@github.com:agenzia/alservice.git
git push -u origin main
```

### 4. Applicazione su Coolify

*New Resource → Application* dal repo del sito, *Build Pack: Dockerfile*, porta 80.

- **Dominio:** all'inizio solo quello di prova (es. `https://alservice.agenzia.it`).
- **Auto Deploy:** attivo. Ogni pubblicazione dal pannello è un push e Coolify aggiorna il sito da solo.

### 5. Collega blog ed email nel pannello

```bash
# blog: legge site.json, template e articoli dal repo
curl -X POST $API/sites/alservice/import -H "Authorization: Bearer $TOKEN"

# email dei form: SMTP Brevo con mittente sul dominio del cliente
curl -X PUT $API/sites/alservice/mail -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -d '{
  "preset": "brevo", "username": "…@smtp-brevo.com", "password": "xsmtpsib-…",
  "from_email": "noreply@alservice.it", "from_name": "Al Service", "recipients": ["info@alservice.it"]
}'
curl -X POST $API/sites/alservice/mail/test -H "Authorization: Bearer $TOKEN"
```

Il mittente (`noreply@alservice.it`) deve essere su un dominio **autenticato su Brevo** (SPF/DKIM).

### 6. Prova sul dominio di prova

Controlla almeno: pagine e menu, invio del form (arriva l'email?), banner cookie, un articolo di prova pubblicato dal pannello.

### 7. Passaggio al dominio vero

1. Su Coolify aggiungi `https://alservice.it` e `https://www.alservice.it` all'applicazione del sito.
2. DNS (Cloudflare): i record `@` e `www` puntano al server Coolify. Con il proxy di Cloudflare attivo, imposta SSL/TLS su **Full (strict)**.
3. Verifica e tieni WordPress spento ma **non cancellato** per qualche settimana.

I vecchi indirizzi WordPress sono già gestiti da `nginx.conf`: `/wp-content/uploads/…` reindirizza a `/media/…`, mentre `/wp-admin`, `/feed` e simili rispondono come pagine inesistenti.

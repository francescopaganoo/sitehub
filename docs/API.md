# SiteHub – API v1

API REST JSON per gestire i siti statici: blog, categorie, moduli (form ed email), pubblicazione.
Il pannello web e il gestionale usano le stesse API.

Per collegare l'HTML di un sito ai moduli vedi [INTEGRAZIONE.md](INTEGRAZIONE.md).

- **Base URL:** `https://<pannello>/api/v1`
- **Formato:** JSON in UTF-8, sia nelle richieste (`Content-Type: application/json`) sia nelle risposte.
- **Date:** ISO 8601 (`2026-10-26T09:00:00+01:00`). Senza fuso orario vengono interpretate nell'ora locale del sito.

## Autenticazione

Ogni richiesta deve avere l'header:

```
Authorization: Bearer ssg_xxxxxxxxxxxxxxxx
```

Ci sono due tipi di token:

| Tipo | Accesso | Uso tipico |
|---|---|---|
| **agenzia** | tutti i siti; creare e configurare siti, import | gestionale, amministrazione |
| **sito** | un solo sito: articoli, categorie, pubblicazione | cliente, integrazioni dedicate |

I token si creano sul server del pannello. Il token in chiaro viene mostrato una volta sola:

```
php panel/bin/token.php create "Gestionale"
php panel/bin/token.php create "Cliente Rossi" --site=rossi
php panel/bin/token.php list
php panel/bin/token.php revoke <id>
```

Con un token di sito, gli altri siti risultano **inesistenti** (404), non vietati.

## Risposte ed errori

- **Successo:** `{"data": …}`. Le liste hanno anche `"meta"`.
- **Errore:** sempre nella stessa forma:

```json
{
  "error": {
    "code": "validation_failed",
    "message": "Dati non validi",
    "fields": { "title": "Obbligatorio", "categories": "Categorie inesistenti: boh" }
  }
}
```

| HTTP | `code` | Quando |
|---|---|---|
| 400 | `invalid_json` | body non è JSON valido |
| 401 | `unauthorized` | token mancante, errato o revocato |
| 403 | `forbidden` | operazione riservata ai token agenzia |
| 404 | `not_found` | sito / articolo / categoria / endpoint inesistente |
| 405 | `method_not_allowed` | metodo HTTP non previsto per quell'URL |
| 409 | `already_imported`, `templates_missing` | stato del sito non compatibile con l'operazione |
| 422 | `validation_failed`, `invalid_target`, `import_failed` | dati non validi (dettaglio in `fields`) |
| 502 | `github_error` | GitHub ha rifiutato la pubblicazione |
| 500 | `internal_error` | errore del pannello |

## Pubblicazione automatica

Se il sito ha `auto_publish: true` (il valore predefinito), ogni modifica che cambia il sito pubblicato viene pubblicata subito. Questo vale per creare, modificare o eliminare un articolo pubblicato e per modificare o eliminare una categoria. La pubblicazione è un commit sul repo del sito, seguito dal deploy di Coolify.

Le risposte a queste operazioni contengono anche `publish`:

```json
"publish": { "revision": "3f2a…", "written": 4, "deleted": 2 }
```

- `revision: null`: non è cambiato nessun file, quindi niente commit.
- `publish: null`: non serviva pubblicare (per esempio è stata salvata una bozza).
- `publish: {"error": "…"}`: **il dato è salvato** ma la pubblicazione è fallita. Si può ripetere con `POST /sites/{site}/publish`.

---

## Siti

### `GET /sites`
Elenca i siti accessibili al token.

### `GET /sites/{site}`
Restituisce un sito.

```json
{
  "data": {
    "slug": "alservice",
    "name": "Al Service",
    "domain": "https://alservice.it",
    "target": { "type": "github", "repo": "agenzia/alservice", "branch": "main" },
    "auto_publish": true,
    "public_key": "pk_be3a44d5e2d9ad7cf76886fc",
    "embed": {
      "script_url": "https://hub.planstudios.it/e/v1/pk_be3a44d5e2d9ad7cf76886fc.js",
      "snippet": "<script src=\"https://hub.planstudios.it/e/v1/pk_be3a44d5e2d9ad7cf76886fc.js\" defer></script>"
    },
    "modules": { "forms": true, "cookies": true, "blog": true },
    "origins": ["staging.alservice.it"],
    "cookies": { "policy_url": "/cookie-policy/", "categories": ["statistics"], "text": "", "color": "#1d4ed8", "version": 1 },
    "blog": { "permalink": "/{slug}/", "category_path": "/category/{slug}/", "index_path": null, "index_title": "Blog", "per_page": 10 },
    "imported": true,
    "submissions": { "total": 12, "unsent": 0, "last_at": "2026-10-26T14:34:34+01:00" },
    "published_at": "2026-10-26T14:14:00+01:00",
    "created_at": "…",
    "updated_at": "…"
  }
}
```

### `POST /sites` (solo agenzia)
Registra un sito. Il suo repo deve contenere l'output del clonatore.

```json
{
  "slug": "alservice",
  "name": "Al Service",
  "domain": "https://alservice.it",
  "target": { "type": "github", "repo": "agenzia/alservice", "branch": "main" },
  "auto_publish": true,
  "modules": { "forms": true, "cookies": true, "blog": true },
  "origins": []
}
```

- `target.type: "local"` con `path` scrive in una cartella del server ed è pensato per sviluppo e test.
- Viene generata la **chiave pubblica** del sito (`public_key`). `embed.snippet` è la riga da inserire nell'HTML.
- `modules`: tutti attivi se omesso.
- `origins`: domini **in più** da cui accettare i form, per esempio staging o anteprime. Il dominio del sito, con e senza `www`, è sempre ammesso.

### `PATCH /sites/{site}` (solo agenzia)
Modifica solo i campi inviati: `name`, `domain`, `target`, `auto_publish`, `modules`, `origins`, `cookies`, `blog`.

`modules` aggiorna solo le chiavi inviate, per esempio `{"modules": {"forms": false}}`. Il cambiamento arriva sul sito entro 5 minuti (cache dello script) e non serve toccare l'HTML.

Campi di `cookies` (banner di consenso, modulo `cookies`). Si aggiornano solo le chiavi inviate:

| Campo | Esempio | Note |
|---|---|---|
| `policy_url` | `"/cookie-policy/"` | link nel banner; vuoto = `/cookie-policy/` |
| `categories` | `["statistics", "marketing"]` | categorie chieste sempre, oltre a quelle trovate nella pagina (`data-ssg-consent`). Nessuna categoria = niente banner |
| `text` | `"Usiamo cookie…"` | testo del banner, massimo 600 caratteri; vuoto = testo predefinito |
| `color` | `"#1d4ed8"` | colore dei pulsanti; vuoto = grigio scuro |
| `reset_consent` | `true` | solo in scrittura: incrementa `version` e il banner ricompare a tutti i visitatori |

Come anche `modules`, le modifiche arrivano sul sito entro 5 minuti. Le convenzioni per l'HTML sono in `INTEGRAZIONE.md`.

Campi di `blog`:

| Campo | Esempio | Note |
|---|---|---|
| `index_path` | `"/blog/"` | pagina elenco di tutti gli articoli; `null` = nessuna |
| `index_title` | `"News"` | titolo della pagina elenco |
| `per_page` | `9` | articoli per pagina (1–100) |
| `permalink` | `"/blog/{slug}/"` | segnaposti `{slug}` (obbligatorio), `{yyyy}`, `{mm}`, `{dd}`, `{id}` |
| `category_path` | `"/categoria/{slug}/"` | |

⚠️ Se cambi `permalink` o `category_path`, cambiano gli URL degli articoli già indicizzati.

### `POST /sites/{site}/import` (solo agenzia)
Legge dal repo del sito i dati prodotti dal clonatore (`site.json`, `templates/`, `content/`) e li carica nel pannello. Se il sito ha già articoli serve `{"replace": true}`.

Risposta: `{"data": {"posts": 12, "categories": 3}}`

### `POST /sites/{site}/publish`
Rigenera e pubblica tutto il blog. È utile quando `auto_publish` è disattivato, per ripetere una pubblicazione fallita o per far uscire gli articoli programmati. Body facoltativo: `{"message": "testo del commit"}`.

### `GET /sites/{site}/publications?limit=20`
Storico delle pubblicazioni: `at`, `actor` (nome del token), `message`, `revision`, `written`, `deleted`, `error`.

### `DELETE /sites/{site}` (solo agenzia)
Elimina il sito da SiteHub insieme a moduli, messaggi, articoli e token del sito. Il repo e il sito pubblicato non vengono toccati: smettono solo di funzionare form e moduli.

### `POST /sites/{site}/rotate-key` (solo agenzia)
Genera una nuova chiave pubblica. ⚠️ L'HTML con la vecchia chiave smette di funzionare finché non si aggiorna lo script.

---

## Articoli

### `GET /sites/{site}/posts`
Parametri facoltativi:

| Parametro | Descrizione |
|---|---|
| `status` | `draft` o `published` |
| `category` | slug della categoria |
| `q` | ricerca nel titolo e nel testo |
| `page`, `per_page` | paginazione (`per_page` va da 1 a 100, predefinito 20) |

Nelle liste gli articoli non hanno il campo `content`. La risposta contiene anche:

```json
"meta": { "page": 1, "per_page": 20, "total": 37, "pages": 2 }
```

### `GET /sites/{site}/posts/{id}`

```json
{
  "data": {
    "id": "af56e3ca7ccc",
    "slug": "tagliando-auto",
    "title": "Tagliando: perché è importante farlo",
    "status": "published",
    "scheduled": false,
    "date": "2026-10-26T14:13:46+01:00",
    "modified": "2026-10-26T14:14:00+01:00",
    "url": "https://alservice.it/tagliando-auto/",
    "excerpt": "Cosa controlliamo durante il tagliando.",
    "categories": ["novita-in-officina"],
    "tags": [],
    "image": { "url": "/wp-content/uploads/2024/03/officina.webp", "alt": "Officina", "width": 1200, "height": 800 },
    "author": "",
    "seo": { "title": "", "description": "", "noindex": false },
    "content": "<h2>Cosa include</h2><p>…</p>"
  }
}
```

- `scheduled: true` indica un articolo pubblicato con data futura: la sua pagina comparirà alla prima pubblicazione dopo quella data.
- Gli articoli importati da WordPress mantengono come `id` l'ID di WordPress (es. `"208"`).

### `POST /sites/{site}/posts`
Crea un articolo. L'unico campo obbligatorio è `title`.

| Campo | Tipo | Note |
|---|---|---|
| `title` | string | max 300 caratteri |
| `slug` | string | facoltativo, generato dal titolo e reso univoco |
| `status` | `draft` \| `published` | predefinito `draft` |
| `date` | ISO 8601 | predefinito: ora; una data futura programma l'uscita |
| `content` | HTML | viene ripulito: niente script, iframe, form o attributi `on*` |
| `excerpt` | testo | se vuoto si usano le prime ~30 parole |
| `categories` | string[] | slug di categorie esistenti |
| `tags` | string[] | |
| `image` | object \| null | `{"url": "/…" o "https://…", "alt": "…", "width": 1200, "height": 800}` |
| `author` | string | |
| `seo` | object | `{"title": "…", "description": "…", "noindex": false}`; se vuoti si usano titolo ed estratto |

Risposta: `201` con `data` e `publish`.

### `PATCH /sites/{site}/posts/{id}`
Accetta gli stessi campi di `POST` e aggiorna solo quelli inviati. Se cambi lo `slug`, la pagina col vecchio indirizzo viene rimossa.

### `DELETE /sites/{site}/posts/{id}`
Elimina l'articolo e rimuove la sua pagina dal sito.

### `GET /sites/{site}/posts/{id}/preview`
Restituisce l'**HTML** della pagina dell'articolo con la grafica del sito, anche se è una bozza. Non pubblica nulla.

---

## Categorie

### `GET /sites/{site}/categories`

```json
{ "data": [ { "slug": "news", "name": "News", "description": "", "seo": { "title": "", "description": "" }, "posts": 4 } ] }
```

### `POST /sites/{site}/categories`
Campi: `name` (obbligatorio), `slug` (facoltativo), `description`, `seo` (`{"title", "description"}`).

### `GET` / `PATCH` / `DELETE /sites/{site}/categories/{slug}`
Se una categoria viene rinominata o eliminata, gli articoli collegati vengono aggiornati di conseguenza.

---

## Form ed email

I messaggi dei form vengono **salvati sempre**, poi inviati via SMTP del sito, con mittente sul dominio del cliente. Se l'invio fallisce, il messaggio resta salvato con stato `failed` e il cron riprova fino a 5 volte, con attesa crescente.

### `GET /sites/{site}/mail`
Restituisce le impostazioni SMTP. La password non viene mai restituita: c'è solo `has_password`.

### `PUT /sites/{site}/mail`
Sostituisce la configurazione SMTP. Per Brevo basta `"preset": "brevo"`, che imposta `smtp-relay.brevo.com`, la porta 587 e STARTTLS:

```json
{
  "preset": "brevo",
  "username": "7a1b2c001@smtp-brevo.com",
  "password": "xsmtpsib-…",
  "from_email": "noreply@alservice.it",
  "from_name": "Al Service",
  "recipients": ["info@alservice.it"]
}
```

| Campo | Note |
|---|---|
| `host`, `port`, `encryption` | `encryption`: `tls` (STARTTLS, porta 587), `ssl` (465), `none` |
| `username`, `password` | per Brevo: login SMTP e **chiave SMTP**. Se ometti `password` resta quella salvata, che è cifrata nel database |
| `from_email`, `from_name` | mittente: il dominio deve essere autenticato su Brevo (SPF/DKIM) |
| `recipients` | destinatari predefiniti di tutti i form del sito |

Se il mittente non è sul dominio del sito, la risposta contiene un avviso in `warnings`.

Invece delle credenziali si può indicare un **account SMTP salvato** (solo token agenzia): il sito usa le sue credenziali e indica solo mittente e destinatari.

```json
{ "smtp_account_id": 1, "from_email": "noreply@alservice.it", "from_name": "Al Service", "recipients": ["info@alservice.it"] }
```

Nella risposta `smtp_account` vale `{"id": 1, "name": "Brevo Plan Studios"}` se il sito usa un account, `null` se ha credenziali proprie.

### `POST /sites/{site}/mail/test`
Invia un'email di prova (body facoltativo: `{"to": "…"}`). In caso di problemi risponde `422 smtp_failed` con l'errore del server, per esempio `SMTP: 535 5.7.8 Authentication failed`.

### Account SMTP (solo agenzia)
Credenziali SMTP riutilizzabili tra i siti, per esempio l'SMTP di un account Brevo. Cambiare la chiave di un account vale subito per tutti i siti che lo usano.

| Metodo | Percorso | Note |
|---|---|---|
| `GET` | `/smtp-accounts` | elenco; ogni account indica in `sites` gli slug dei siti che lo usano |
| `POST` | `/smtp-accounts` | `name`, `host`, `port`, `encryption`, `username`, `password` (oppure `"preset": "brevo"`) |
| `GET` / `PATCH` | `/smtp-accounts/{id}` | `PATCH` modifica solo i campi inviati; senza `password` resta quella salvata |
| `DELETE` | `/smtp-accounts/{id}` | `409 account_in_use` se qualche sito lo usa |
| `POST` | `/smtp-accounts/{id}/test` | `{"to": "…", "from_email": "…"}`: email di prova con le credenziali dell'account |

La password non viene mai restituita: c'è solo `has_password`.

### `GET /sites/{site}/forms`
Elenca i moduli del sito. **Si registrano da soli al primo invio**, col nome preso da `data-ssg-form`.

```json
{
  "key": "contatti",
  "name": "Contatti",
  "enabled": true,
  "recipients": [],
  "subject": "",
  "success_message": "",
  "autoreply": { "enabled": false, "subject": "", "body": "" }
}
```

### `PATCH /sites/{site}/forms/{key}`
Campi modificabili:

| Campo | Note |
|---|---|
| `name` | nome mostrato nelle email |
| `enabled` | `false` = il modulo rifiuta gli invii |
| `recipients` | destinatari di questo modulo; vuoto = quelli predefiniti del sito |
| `subject` | oggetto dell'email; vuoto = "Nuovo messaggio dal sito …" |
| `success_message` | messaggio mostrato al visitatore dopo l'invio |
| `autoreply` | `{"enabled": true, "subject": "…", "body": "…"}`: risposta automatica al visitatore, se ha indicato un'email |

### `GET /sites/{site}/submissions`
Elenca i messaggi ricevuti. Filtri: `form`, `status` (`sent`, `failed`, `pending`, `spam`), `page`, `per_page`.

```json
{
  "id": 1,
  "form": "contatti",
  "status": "sent",
  "fields": [
    { "name": "form_fields[name]", "label": "Nome", "type": "text", "value": "Mario Rossi" },
    { "name": "form_fields[email]", "label": "Email", "type": "email", "value": "mario.rossi@example.com" }
  ],
  "reply_to": "mario.rossi@example.com",
  "page": "https://alservice.it/",
  "attempts": 1,
  "last_error": null,
  "created_at": "2026-10-26T14:34:34+01:00",
  "sent_at": "2026-10-26T14:34:34+01:00"
}
```

I messaggi con stato `spam` (honeypot compilato o form inviato in meno di 2 secondi) non vengono inviati, ma restano consultabili.

### `GET` / `DELETE /sites/{site}/submissions/{id}`
### `POST /sites/{site}/submissions/{id}/resend`
Reinvia il messaggio, per esempio dopo aver corretto l'SMTP o i destinatari.

---

## Esempio completo (curl)

```bash
TOKEN=ssg_…
API=https://hub.planstudios.it/api/v1

# nuovo articolo pubblicato subito
curl -X POST $API/sites/alservice/posts \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"title": "Promo cambio gomme", "status": "published", "categories": ["news"],
       "content": "<p>Fino al 30 novembre…</p>"}'

# portarlo in bozza (lo toglie dal sito)
curl -X PATCH $API/sites/alservice/posts/<id> \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"status": "draft"}'
```

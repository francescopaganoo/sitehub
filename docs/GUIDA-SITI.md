<!--
  Questo file genera due guide (SiteHub, classe Panel\Guide):
    https://hub.planstudios.it/guida.md              generica: tutti i blocchi "positivi" (form, blog, consenso) e "generica"
    https://hub.planstudios.it/e/v1/{chiave}/guida.md su misura per un sito: solo i blocchi che valgono per quel sito
  Blocchi condizionali: una riga «se:NOME» … una riga «/se», scritte come commenti HTML (vedi sotto); si possono annidare.
  Nomi: generica, sito, form, no-form, blog, no-blog, consenso, tecnici, sito-consenso
  Segnaposti della guida su misura: %NOME_SITO%, %DOMINIO%, %MODULI%, %URL_GUIDA%, %CATEGORIE%
-->
<!-- se:generica -->
# Guida per realizzare un sito statico (Plan Studios + SiteHub)

Questa guida è per chi realizza l'HTML di un sito per l'agenzia, a mano o con un LLM (ChatGPT, Claude…).
Seguendola, ogni sito ha la stessa struttura, funziona su Coolify senza configurazioni particolari
e si collega a SiteHub (form, banner cookie, blog) senza modifiche.

> **Per chi usa un LLM:** dai all'LLM questo file per intero, insieme ai testi e alle indicazioni grafiche del sito.
> Se il sito è già registrato su SiteHub, usa invece la **guida del sito** che trovi nel gestionale
> (Siti & Domini → Siti statici → il sito → "Guida del sito"): contiene la chiave del sito e solo le regole dei moduli attivi.
>
> **Per l'LLM che legge:** segui tutte le regole di questa guida. Se una richiesta le contraddice, segnalalo prima di procedere.
<!-- /se -->
<!-- se:sito -->
# Guida per realizzare il sito «%NOME_SITO%» (Plan Studios + SiteHub)

Questa guida è per chi realizza o modifica l'HTML del sito **%DOMINIO%**, a mano o con un LLM (ChatGPT, Claude…).
È su misura: contiene la chiave del sito già inserita e solo le regole che valgono per questo sito.

> **Il sito:** %MODULI%.
> Se nel gestionale cambiano i moduli o i servizi usati, scarica di nuovo la guida da %URL_GUIDA%
>
> **Per l'LLM che legge:** segui tutte le regole di questa guida. Se una richiesta le contraddice, segnalalo prima di procedere.
> Se il sito esiste già, applica solo quello che manca, senza cambiare grafica, testi e struttura delle pagine.
<!-- /se -->

Indice
1. Le regole in breve
2. Tecnologia
3. Cartelle e file
4. Indirizzi e link
5. Le pagine HTML
6. CSS
7. JavaScript
8. Immagini e font
9. SEO
10. Accessibilità
11. Pagine obbligatorie e footer
12. Collegamento a SiteHub (form, cookie, blog)
13. File per la pubblicazione
14. Messa online
15. Da non fare
16. Controlli finali
17. Esempio di richiesta a un LLM

---

## 1. Le regole in breve

- Solo **HTML, CSS e JavaScript** scritti a mano: niente framework, niente compilazione, niente npm.
- **Una cartella per pagina**: `chi-siamo/index.html`, raggiungibile come `/chi-siamo/`.
- Link e risorse sempre con **percorsi che partono da `/`**: `/chi-siamo/`, `/assets/img/logo.webp`.
- **Un solo `style.css` e un solo `script.js`** nella radice, tutto il resto in `assets/`.
- Tutto **nel sito**: font, immagini e icone sono file del sito, mai caricati da altri domini.
- Sempre presenti: `404.html`, `privacy-policy/`, `cookie-policy/`, `robots.txt`, `sitemap.xml`, `Dockerfile`, `nginx.conf`.
- In ogni pagina, prima di `</body>`, **lo script di SiteHub**.
<!-- se:form -->
- I form di contatto hanno **`data-ssg-form`** e nessun `action`.
<!-- /se -->
<!-- se:no-form -->
- **Niente form di contatto**: il modulo form non è attivo per questo sito.
<!-- /se -->
<!-- se:consenso -->
- Gli script che tracciano (Analytics, Pixel…) e le mappe/video incorporati **aspettano il consenso** (`data-ssg-consent`).
<!-- /se -->
<!-- se:tecnici -->
- **Solo cookie tecnici**: niente Analytics, pixel, mappe o video incorporati, niente banner cookie.
<!-- /se -->
<!-- se:blog -->
- Il blog lo genera SiteHub dai **due template** in `_sitehub/templates/`: niente articoli scritti a mano.
<!-- /se -->
<!-- se:no-blog -->
- **Niente blog**: il sito non ha il blog, niente pagine di articoli.
<!-- /se -->
- Dopo ogni modifica a `style.css` o `script.js`: **`./versione.sh`**.

---

## 2. Tecnologia

- **HTML5** semantico, **CSS** moderno (variabili, flexbox, grid), **JavaScript** senza librerie.
- Il sito si apre e si legge anche con JavaScript disattivato: JS aggiunge comodità (menu mobile, galleria), non contenuti.
- **Non usare:** React, Vue, Next, Astro, Tailwind, Bootstrap, jQuery, Sass, npm, file da compilare, CDN esterni.
- Il sito deve funzionare aprendo i file così come sono, serviti da un qualsiasi server web.

---

## 3. Cartelle e file

```
sito/
├── index.html                 home
├── chi-siamo/index.html       una cartella per ogni pagina
├── servizi/index.html
├── contatti/index.html        (se il sito ha più pagine; altrimenti contatti nella home)
├── privacy-policy/index.html  obbligatoria
├── cookie-policy/index.html   obbligatoria
├── 404.html                   obbligatoria, nella radice
├── style.css                  tutto lo stile
├── script.js                  tutto il JavaScript
├── assets/
│   ├── img/                   immagini (webp), anche in sottocartelle: img/servizi/, img/galleria/
│   ├── fonts/                 font in woff2
│   └── icons/                 eventuali icone svg
├── robots.txt
├── sitemap.xml
<!-- se:blog -->
├── _sitehub/                  template del blog letti da SiteHub (sezione 12), mai pubblicati
│   └── templates/
│       ├── articolo.html
│       └── blog.html
<!-- /se -->
├── Dockerfile                 pubblicazione su Coolify (vedi sezione 13)
├── nginx.conf
├── .dockerignore
├── .gitignore
├── versione.sh                aggiorna la versione di CSS e JS nei link
└── README.md                  come è fatto il sito, in poche righe
```

Nomi di file e cartelle: **minuscole, senza spazi né accenti, parole separate da trattini** (`cambio-gomme`, `foto-officina.webp`).

---

## 4. Indirizzi e link

- Ogni pagina è `nome-pagina/index.html` e si raggiunge come **`/nome-pagina/`**, con la barra finale. Mai `.html` negli indirizzi.
- Link interni sempre assoluti dalla radice: `href="/servizi/"`, `src="/assets/img/logo.webp"`.
  Mai `../`, mai `servizi.html`, mai il dominio completo per i link interni.
- Link a una sezione di un'altra pagina: `href="/#contatti"`. Le sezioni hanno un `id` corto e leggibile.
- Link esterni: `target="_blank" rel="noopener"`.
- Telefono ed email cliccabili: `href="tel:+390000000000"`, `href="mailto:info@cliente.it"`.

---

## 5. Le pagine HTML

Ogni pagina parte da questo schema. Testi, colori e contenuti cambiano; la struttura no.

```html
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Titolo della pagina - Nome dell'azienda</title>
  <meta name="description" content="Descrizione della pagina, 140-160 caratteri, diversa per ogni pagina.">
  <link rel="canonical" href="https://www.dominio-cliente.it/nome-pagina/">

  <meta property="og:type" content="website">
  <meta property="og:locale" content="it_IT">
  <meta property="og:site_name" content="Nome dell'azienda">
  <meta property="og:title" content="Titolo della pagina">
  <meta property="og:description" content="Descrizione breve.">
  <meta property="og:url" content="https://www.dominio-cliente.it/nome-pagina/">
  <meta property="og:image" content="https://www.dominio-cliente.it/assets/img/condivisione.webp">
  <meta name="twitter:card" content="summary_large_image">

  <link rel="icon" href="/assets/img/favicon.webp">
  <link rel="apple-touch-icon" href="/assets/img/favicon.webp">
  <link rel="preload" href="/assets/fonts/nome-font.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="/style.css?v=1">
</head>
<body>
  <a class="skip-link" href="#contenuto">Vai al contenuto</a>

  <header class="site-header">
    <nav aria-label="Menu principale">
      <a class="logo" href="/"><img src="/assets/img/logo.webp" alt="Nome dell'azienda" width="300" height="100"></a>
      <!-- menu: link alle pagine e alle sezioni -->
    </nav>
  </header>

  <main id="contenuto">
    <h1>Un solo h1 per pagina</h1>
    <!-- sezioni della pagina -->
  </main>

  <footer class="site-footer">
    <!-- vedi sezione 11 -->
  </footer>

  <script src="/script.js?v=1" defer></script>
  <!-- per ultimo: lo script di SiteHub (sezione 12) -->
</body>
</html>
```

Regole:
- `lang="it"` (o la lingua del sito), `charset` e `viewport` sempre presenti.
- **Header e footer identici in tutte le pagine**, compresa la 404 e le pagine legali.
- Un solo `<h1>` per pagina, poi `<h2>`, `<h3>` in ordine, senza saltare livelli.
- Elementi semantici: `header`, `nav`, `main`, `section`, `article`, `footer`.
- Niente stili in linea (`style="…"`) e niente JavaScript in linea (`onclick="…"`), salvo casi isolati.
- Il numero dopo `?v=` lo aggiorna `versione.sh`: scrivi `?v=1` la prima volta e poi lascia fare allo script.

---

## 6. CSS

- Un solo file, `style.css`, con un commento iniziale che spiega l'ordine delle sezioni.
- In cima: i font (`@font-face`) e le **variabili** in `:root` (colori, font, larghezza massima, spaziature).
  Cambiando le variabili si aggiorna tutto il sito.

```css
@font-face {
  font-family: "NomeFont";
  font-style: normal;
  font-weight: 100 900;
  font-display: swap;
  src: url(assets/fonts/nome-font.woff2) format("woff2");
}

:root {
  --colore-primario: #1d4ed8;
  --colore-testo: #1f2937;
  --sfondo: #ffffff;
  --font: "NomeFont", system-ui, sans-serif;
  --larghezza: 1200px;
}
```

- Nomi delle classi leggibili e coerenti (`.servizi__card`, `.btn--primario`).
- **Responsive**: il sito va provato almeno a 375 px (telefono), 768 px (tablet), 1366 e 1920 px. Niente scroll orizzontale.
- Una classe `.sr-only` per i testi visibili solo agli screen reader (es. label nascoste dei form).
- Animazioni leggere, disattivate con `@media (prefers-reduced-motion: reduce)`.
- Evitare `!important`.

---

## 7. JavaScript

- Un solo file, `script.js`, caricato con `defer`. Niente librerie.
- Solo comportamenti: menu mobile, galleria, scorrimento, piccoli effetti. I contenuti stanno nell'HTML.
<!-- se:form -->
- **Non gestire l'invio dei form**: lo fa SiteHub (sezione 12).
<!-- /se -->
- **Non creare banner cookie** e non leggere o scrivere cookie: lo fa SiteHub.
- Il codice deve funzionare anche se un elemento non è presente nella pagina (controlla prima di usarlo).

---

## 8. Immagini e font

- Formato **WebP** (SVG per loghi e icone semplici). Niente PNG o JPG pesanti.
- Dimensioni giuste per l'uso: immagine a tutta larghezza al massimo 1920 px; foto nelle card 800 px circa.
  Peso indicativo: sotto i 200 KB, sotto i 400 KB per le immagini grandi.
- Ogni `<img>` ha **`alt`** (descrive l'immagine; vuoto `alt=""` solo se è decorativa) e **`width` e `height`** reali.
- `loading="lazy"` su tutte le immagini **tranne** quelle visibili all'apertura della pagina (logo, prima immagine).
- Favicon: `assets/img/favicon.webp`, quadrata, almeno 192×192 px.
- Immagine per la condivisione sui social (og:image): 1200×630 px.
- **Font**: file `.woff2` salvati in `assets/fonts/`, con `font-display: swap`.
  Mai Google Fonts o Adobe Fonts collegati: inviano i dati dei visitatori a terzi e richiedono consenso.
- Icone: SVG nell'HTML o in `assets/icons/`. Niente Font Awesome o librerie di icone da CDN.

---

## 9. SEO

- `<title>` e `meta description` **diversi per ogni pagina**.
- `link rel="canonical"` con l'indirizzo completo e definitivo della pagina (dominio vero del cliente, `https`, barra finale).
- **Dati strutturati** nella home (JSON-LD), con il tipo più adatto all'attività (`LocalBusiness`, `AutoRepair`, `Restaurant`…):

```html
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "LocalBusiness",
  "name": "Nome dell'azienda",
  "url": "https://www.dominio-cliente.it/",
  "logo": "https://www.dominio-cliente.it/assets/img/logo.webp",
  "telephone": "+39 000 000 0000",
  "email": "info@dominio-cliente.it",
  "address": {
    "@type": "PostalAddress",
    "streetAddress": "Via Esempio 1",
    "postalCode": "00000",
    "addressLocality": "Città",
    "addressCountry": "IT"
  }
}
</script>
```

- `robots.txt`:

```
User-agent: *
Allow: /

Sitemap: https://www.dominio-cliente.it/sitemap.xml
<!-- se:blog -->
Sitemap: https://www.dominio-cliente.it/sitemap-blog.xml
<!-- /se -->
```

- `sitemap.xml` con **tutte** le pagine (non la 404). Quando aggiungi una pagina, aggiungila qui:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url><loc>https://www.dominio-cliente.it/</loc></url>
  <url><loc>https://www.dominio-cliente.it/chi-siamo/</loc></url>
  <url><loc>https://www.dominio-cliente.it/privacy-policy/</loc></url>
  <url><loc>https://www.dominio-cliente.it/cookie-policy/</loc></url>
</urlset>
```

- La `404.html` ha `<meta name="robots" content="noindex">`.
- Se il sito sostituisce un vecchio sito (es. WordPress), i vecchi indirizzi importanti vanno reindirizzati: vedi `nginx.conf` (sezione 13).

---

## 10. Accessibilità

- Contrasto sufficiente tra testo e sfondo (almeno 4.5:1 per il testo normale).
- Tutto usabile da tastiera: menu, pulsanti, galleria. Il focus deve vedersi (`:focus-visible`).
- Link "Vai al contenuto" all'inizio della pagina (`.skip-link`, visibile al focus).
- Pulsanti veri (`<button>`) per le azioni, link (`<a>`) per andare altrove.
- Il menu mobile ha `aria-expanded` e `aria-controls` sul pulsante che lo apre.
<!-- se:form -->
- Ogni campo dei form ha una `<label>` (anche nascosta con `.sr-only`).
<!-- /se -->

---

## 11. Pagine obbligatorie e footer

- **`404.html`** nella radice: stesso header e footer del sito, un messaggio chiaro e un link alla home, `noindex`.
  Usa percorsi da `/` (la 404 viene mostrata a qualunque indirizzo).
- **`privacy-policy/`**: informativa sul trattamento dei dati, in particolare quelli del form di contatto.
<!-- se:generica -->
- **`cookie-policy/`**: quali cookie usa il sito. Se il sito non usa Analytics, pixel, mappe o video incorporati,
  usa solo cookie tecnici e va scritto così, senza banner. Se li usa, elencali e aggiungi il link per cambiare le preferenze
  (`<a href="/#cookie-settings">cambia le preferenze sui cookie</a>`).
<!-- /se -->
<!-- se:sito-consenso -->
- **`cookie-policy/`**: elenca i cookie tecnici e quelli dei servizi usati dal sito (%CATEGORIE%), con il link per cambiare le preferenze
  (`<a href="/#cookie-settings">cambia le preferenze sui cookie</a>`).
<!-- /se -->
<!-- se:tecnici -->
- **`cookie-policy/`**: il sito usa **solo cookie tecnici** e va scritto così: nessun cookie di profilazione, statistico o pubblicitario,
  per questo nessun banner di consenso.
<!-- /se -->
- I testi legali li fornisce o approva il cliente: non inventare dati societari.

Il **footer** di tutte le pagine contiene almeno:
- ragione sociale, indirizzo, **partita IVA**, telefono ed email;
- link a **Privacy Policy** e **Cookie Policy**;
<!-- se:consenso -->
- se il sito usa cookie che richiedono consenso, il link **Preferenze cookie** (sezione 12).
<!-- /se -->

---

## 12. Collegamento a SiteHub

SiteHub è il sistema dell'agenzia che gestisce **form di contatto, banner cookie e blog** dei siti statici.
L'HTML non contiene logica per questi moduli: bastano le poche convenzioni che seguono.
<!-- se:sito -->
Per questo sito: %MODULI%.
<!-- /se -->

### Lo script (obbligatorio)

Una riga, **identica in tutte le pagine** (comprese 404 e pagine legali), come ultima cosa prima di `</body>`:

```html
<script src="https://hub.planstudios.it/e/v1/pk_XXXXXXXX.js" defer></script>
```

<!-- se:generica -->
La riga esatta, con la chiave `pk_…` del sito, è nel gestionale nella pagina del sito ("Codice da inserire nel sito").
Se il sito non è ancora registrato, lascia un commento `<!-- script SiteHub -->` al suo posto: si aggiunge dopo.
<!-- /se -->
<!-- se:sito -->
È la riga esatta di questo sito: copiala così com'è.
<!-- /se -->
La chiave è pubblica: può stare nell'HTML.

<!-- se:form -->
### Form di contatto

```html
<form data-ssg-form="contatti">
  <label for="nome">Nome</label>
  <input id="nome" name="nome" type="text" autocomplete="name" required>

  <label for="email">Email</label>
  <input id="email" name="email" type="email" autocomplete="email" required>

  <label for="telefono">Telefono</label>
  <input id="telefono" name="telefono" type="tel" autocomplete="tel">

  <label for="messaggio">Messaggio</label>
  <textarea id="messaggio" name="messaggio" rows="5" required></textarea>

  <label><input type="checkbox" name="privacy" value="Sì" required> Ho letto la <a href="/privacy-policy/">privacy policy</a></label>

  <button type="submit">Invia</button>
</form>
```

- `data-ssg-form` con il nome del modulo. Più form con scopi diversi = nomi diversi (`contatti`, `preventivo`, `lavora-con-noi`).
- **Niente `action`**, niente `onsubmit`, niente JavaScript per l'invio: lo fa lo script di SiteHub, antispam compreso.
- Ogni campo ha `name` e una `<label>` collegata. Email con `type="email"`, telefono con `type="tel"`, obbligatori con `required`.
- Casella della privacy obbligatoria, con link alla privacy policy.
- Gruppi di checkbox o radio dentro `<fieldset>` con `<legend>`.
- Niente campi `type="file"` (allegati) o `type="password"`.
- Niente servizi esterni per form o antispam (Formspree, Google Forms, reCAPTCHA, hCaptcha…).
- Facoltativo: messaggi personalizzati dentro il form, nascosti finché non servono. Senza, lo script mostra un messaggio automatico.

```html
<p data-ssg-success hidden>Grazie, ti risponderemo al più presto.</p>
<p data-ssg-error hidden>Invio non riuscito, riprova più tardi.</p>
```

- Facoltativo: `data-ssg-redirect="/grazie/"` sul `<form>` porta a una pagina di ringraziamento dopo l'invio.
- Stile: i messaggi automatici hanno le classi `.ssg-msg`, `.ssg-msg-ok`, `.ssg-msg-ko`, se vuoi adattarli alla grafica.
<!-- /se -->
<!-- se:no-form -->
### Form di contatto

Il modulo form **non è attivo** per questo sito: non aggiungere form di contatto.
Per i contatti usa link a telefono (`tel:`), email (`mailto:`) ed eventualmente WhatsApp.
Se serve un form, chiedi all'agenzia di attivare il modulo: questa guida si aggiorna con le istruzioni.
<!-- /se -->

<!-- se:consenso -->
### Cookie e servizi di terze parti

<!-- se:sito-consenso -->
Questo sito usa servizi che richiedono il consenso dei visitatori: **%CATEGORIE%**.

<!-- /se -->
Il banner cookie lo mostra SiteHub, e **solo** se il sito usa cookie che richiedono consenso. Non crearne uno.

Ogni script che traccia i visitatori (Google Analytics, Tag Manager, Meta Pixel, Hotjar, Clarity…)
si scrive con `type="text/plain"` e la categoria in `data-ssg-consent`: parte solo dopo il consenso.

```html
<script type="text/plain" data-ssg-consent="statistics" src="https://www.googletagmanager.com/gtag/js?id=G-XXXX" async></script>
<script type="text/plain" data-ssg-consent="statistics">
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', 'G-XXXX');
</script>
```

- Categorie: `statistics` (statistiche, analytics) e `marketing` (pubblicità, pixel, remarketing).
- Mappe e video incorporati (Google Maps, YouTube): `data-ssg-src` al posto di `src`, categoria `marketing`.
  Finché manca il consenso, al loro posto compare un avviso con il pulsante "Consenti e mostra".

```html
<iframe data-ssg-consent="marketing" data-ssg-src="https://www.google.com/maps/embed?pb=…"
        width="600" height="400" style="border:0" loading="lazy" title="Mappa"></iframe>
```

- Se il sito usa almeno uno di questi servizi, nel footer di tutte le pagine:

```html
<a href="#cookie-settings">Preferenze cookie</a>
```

  (se manca, SiteHub mostra un piccolo pulsante fisso per riaprire le preferenze).
- Facoltativo, per usare il font del sito nel banner: `:root { --ssg-font: "NomeFont", sans-serif; }`.
- In alternativa a una mappa incorporata va benissimo un link "Apri in Google Maps": nessun consenso necessario.
<!-- /se -->
<!-- se:tecnici -->
### Cookie e servizi di terze parti

Questo sito usa **solo cookie tecnici**: nessun banner, nessun consenso da chiedere.

- Non aggiungere script che tracciano i visitatori (Google Analytics, Tag Manager, Meta Pixel, Hotjar, Clarity…).
- Non incorporare mappe o video (Google Maps, YouTube, Vimeo): usa un link "Apri in Google Maps" o un link al video.
- Non caricare font, icone o altre risorse da altri domini.
- Non creare banner cookie e non leggere o scrivere cookie.
- Se servono statistiche, mappe o video incorporati, chiedi all'agenzia: si attivano nel gestionale e questa guida si aggiorna con le istruzioni.
<!-- /se -->

<!-- se:blog -->
### Blog

Gli articoli li scrive l'agenzia dal gestionale; SiteHub genera le pagine del blog **con la grafica del sito**
usando due template HTML che crei tu, e le pubblica nel repo (commit + deploy automatico).

**Non creare a mano pagine di articoli né elenchi di articoli definitivi.** Crea invece:

1. **`_sitehub/templates/articolo.html`**: la pagina di un articolo.
2. **`_sitehub/templates/blog.html`**: l'elenco degli articoli (usato per `/blog/`, per le pagine delle categorie e per `/blog/page/2/`…).
3. La voce **"Blog"** nel menu di tutte le pagine, con link a `/blog/`.
4. In `robots.txt` la riga `Sitemap: https://www.dominio-cliente.it/sitemap-blog.xml` (sotto quella di `sitemap.xml`).

Indirizzi generati da SiteHub (non creare file in queste cartelle):
- `/blog/` elenco, `/blog/page/2/` pagine successive
- `/blog/titolo-articolo/` articolo
- `/blog/categoria/nome-categoria/` articoli di una categoria
- `/sitemap-blog.xml` sitemap del blog (la tua `sitemap.xml` resta tua: non aggiungerci gli articoli)

**Come si fanno i template.** Sono pagine HTML normali del sito (stesso `<head>`, header e footer di tutte le altre pagine,
script di SiteHub compreso), in cui al posto dei contenuti ci sono dei **segnaposti** tra doppie graffe.
Il modo più semplice: costruisci prima una pagina articolo e una pagina elenco di esempio con contenuti finti, controlla la grafica,
poi copiale in `_sitehub/templates/` sostituendo i contenuti con i segnaposti.

Come funzionano i segnaposti:

| Scrittura | Effetto |
|---|---|
| `{{title}}` | inserisce il valore (testo sicuro: `<` e `"` vengono convertiti) |
| `{{{content}}}` | inserisce HTML così com'è: **tre graffe**, solo per `content`, `pagination` e `schema` |
| `{{#posts}} … {{/posts}}` | ripete il blocco per ogni elemento della lista |
| `{{#image}} … {{/image}}` | mostra il blocco solo se il valore c'è (es. l'immagine) |
| `{{^posts}} … {{/posts}}` | mostra il blocco solo se il valore è vuoto (es. "nessun articolo") |
| `{{image.url}}` | un campo dentro un altro |

**Segnaposti di `articolo.html`**

| Segnaposto | Contenuto |
|---|---|
| `{{title}}` | titolo dell'articolo (**obbligatorio**, nell'`<h1>`) |
| `{{{content}}}` | testo dell'articolo in HTML (**obbligatorio**, tre graffe) |
| `{{seo_title}}` | titolo per `<title>` e `og:title` |
| `{{seo_description}}` | descrizione per `meta description` e `og:description` |
| `{{canonical}}` | indirizzo completo della pagina, per `canonical` e `og:url` |
| `{{robots}}` | per `<meta name="robots">` (`index, follow` o `noindex, follow`) |
| `{{og_image}}` | indirizzo completo dell'immagine, per `og:image` (vuoto se non c'è) |
| `{{{schema}}}` | dati strutturati, dentro `<script type="application/ld+json">` |
| `{{date_iso}}` / `{{date_human}}` | data in formato tecnico (`<time datetime>`) e leggibile ("27 ottobre 2026") |
| `{{modified_iso}}` | data dell'ultima modifica |
| `{{excerpt}}` | riassunto |
| `{{author}}` | autore (può essere vuoto) |
| `{{site_name}}` | nome del sito |
| `{{#image}}…{{/image}}` con `{{image.url}}`, `{{image.alt}}`, `{{image.width}}`, `{{image.height}}` | immagine in evidenza, se c'è |
| `{{#categories}}…{{/categories}}` con `{{name}}`, `{{url}}` | categorie dell'articolo |
| `{{url}}`, `{{slug}}` | indirizzo relativo (`/blog/titolo/`) e slug |

**Segnaposti di `blog.html`**

| Segnaposto | Contenuto |
|---|---|
| `{{#posts}}…{{/posts}}` | **obbligatorio**: un blocco per articolo; dentro valgono i segnaposti dell'articolo (`{{title}}`, `{{url}}` **obbligatorio**, `{{excerpt}}`, `{{date_human}}`, `{{image.url}}`…) |
| `{{^posts}}…{{/posts}}` con `{{empty_text}}` | messaggio quando non ci sono articoli |
| `{{archive_title}}` | "Blog" oppure il nome della categoria |
| `{{description}}` | descrizione della categoria (può essere vuota) |
| `{{{pagination}}}` | link alle pagine successive (tre graffe) |
| `{{seo_title}}`, `{{seo_description}}`, `{{canonical}}`, `{{robots}}`, `{{{schema}}}`, `{{site_name}}` | come nell'articolo |

⚠️ **Dentro `{{#image}} … {{/image}}`, `{{url}}` è l'indirizzo dell'immagine, non dell'articolo.**
Metti quindi il link all'articolo **fuori** dal blocco dell'immagine e usa sempre la forma `{{image.url}}`, come nell'esempio.

**Esempio di `articolo.html`** (header, footer e il resto del `<head>` sono quelli del sito):

```html
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{seo_title}}</title>
  <meta name="description" content="{{seo_description}}">
  <meta name="robots" content="{{robots}}">
  <link rel="canonical" href="{{canonical}}">
  <meta property="og:type" content="article">
  <meta property="og:locale" content="it_IT">
  <meta property="og:site_name" content="{{site_name}}">
  <meta property="og:title" content="{{seo_title}}">
  <meta property="og:description" content="{{seo_description}}">
  <meta property="og:url" content="{{canonical}}">
  {{#og_image}}<meta property="og:image" content="{{og_image}}">{{/og_image}}
  <link rel="icon" href="/assets/img/favicon.webp">
  <link rel="stylesheet" href="/style.css?v=1">
  <script type="application/ld+json">{{{schema}}}</script>
</head>
<body>
  <!-- header del sito, identico alle altre pagine -->

  <main id="contenuto">
    <article class="articolo">
      <p class="articolo__meta">
        <a href="/blog/">Blog</a> · <time datetime="{{date_iso}}">{{date_human}}</time>
        {{#categories}} · <a href="{{url}}">{{name}}</a>{{/categories}}
      </p>
      <h1>{{title}}</h1>
      {{#image}}<img class="articolo__immagine" src="{{image.url}}" alt="{{image.alt}}" width="{{image.width}}" height="{{image.height}}">{{/image}}
      <div class="articolo__testo">{{{content}}}</div>
    </article>
  </main>

  <!-- footer del sito, identico alle altre pagine -->
  <script src="/script.js?v=1" defer></script>
  <script src="https://hub.planstudios.it/e/v1/pk_XXXXXXXX.js" defer></script>
</body>
</html>
```

**Esempio della parte centrale di `blog.html`** (il resto come sopra, con `og:type` = `website` e senza `og:image`):

```html
  <main id="contenuto">
    <section class="blog">
      <h1>{{archive_title}}</h1>
      {{#description}}<p>{{description}}</p>{{/description}}

      <div class="blog__griglia">
        {{#posts}}
        <article class="blog__card">
          <a href="{{url}}">
            {{#image}}<img src="{{image.url}}" alt="{{image.alt}}" loading="lazy">{{/image}}
            <h2>{{title}}</h2>
          </a>
          <p class="blog__data"><time datetime="{{date_iso}}">{{date_human}}</time></p>
          <p>{{excerpt}}</p>
        </article>
        {{/posts}}
      </div>
      {{^posts}}<p>{{empty_text}}</p>{{/posts}}

      {{{pagination}}}
    </section>
  </main>
```

Stile: aggiungi in `style.css` le classi dei template (`.articolo`, `.blog__card`…) e lo stile del testo degli articoli
(`.articolo__testo h2, p, ul, img, blockquote, a`), che arriva dall'editor del gestionale come HTML semplice.
La paginazione ha queste classi: `.pagination`, `.page-numbers`, `.current`, `.prev`, `.next`.

Regole:
- I template devono stare **esattamente** in `_sitehub/templates/articolo.html` e `_sitehub/templates/blog.html`.
- Header, footer e `<head>` dei template devono restare **uguali a quelli delle altre pagine**:
  se modifichi header o footer del sito, **aggiorna anche i due template**. Le pagine del blog si rigenerano alla pubblicazione successiva di un articolo
  (o con "Pubblica di nuovo tutto il blog" nel gestionale).
- Niente articoli scritti a mano dentro `/blog/`: verrebbero sostituiti.
- Quando i template sono nel repo, il blog si collega dal gestionale (pagina del sito → Blog → **Collega il blog**):
  SiteHub controlla i template e segnala cosa manca (es. "manca {{{content}}} in articolo.html") o i segnaposti scritti male.
<!-- /se -->
<!-- se:no-blog -->
### Blog

Questo sito **non ha il blog**: non creare pagine di articoli, elenchi di articoli o la cartella `_sitehub/templates/`.
Se il cliente vuole un blog, l'agenzia lo attiva nel gestionale e questa guida si aggiorna con le istruzioni.
<!-- /se -->

---

## 13. File per la pubblicazione

Questi file sono uguali per tutti i siti. Copiali così come sono.

**`Dockerfile`**

```dockerfile
# Sito statico (Coolify: build pack "Dockerfile", porta 80)
FROM nginx:1.27-alpine
COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY . /usr/share/nginx/html/
# la configurazione e i file di SiteHub (template, copia degli articoli) non devono essere pubblici
RUN rm -rf /usr/share/nginx/html/nginx.conf /usr/share/nginx/html/_sitehub
```

**`nginx.conf`**: pagina 404, cache, compressione e intestazioni di sicurezza.
Il sito funziona anche senza (una cartella per pagina), qui ci sono solo gli extra.

```nginx
# pagine HTML: il browser verifica sempre se sono cambiate (CSS e JS hanno la versione nell'indirizzo)
map $sent_http_content_type $html_cache {
    ~text/html "no-cache";
    default    "";
}

server {
    listen 80;
    server_name _;
    root /usr/share/nginx/html;
    index index.html;

    # dietro il proxy di Coolify: redirect relativi (restano su https)
    absolute_redirect off;
    server_tokens off;

    gzip on;
    gzip_min_length 1024;
    gzip_types text/css application/javascript image/svg+xml text/plain;

    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header Cache-Control $html_cache always;

    # immagini, font, CSS e JS: cache lunga
    location /assets/ {
        expires 30d;
        add_header Cache-Control "public" always;
        add_header X-Content-Type-Options "nosniff" always;
    }

    # template e copia degli articoli di SiteHub (anche le bozze): mai pubblici
    location ^~ /_sitehub/ { return 404; }

    error_page 404 /404.html;
}
```

Solo se il sito sostituisce un sito WordPress, dentro `server { … }` aggiungi:

```nginx
    # vecchi indirizzi del sito WordPress
    location ^~ /wp-content/ { return 301 /; }
    location ~ ^/(wp-admin|wp-login\.php|xmlrpc\.php|wp-json|feed) { return 404; }
    # pagine con un indirizzo nuovo, una riga per pagina:
    # location = /vecchio-indirizzo/ { return 301 /nuovo-indirizzo/; }
```

**`.dockerignore`**: file che non devono finire sul sito pubblicato.

```
Dockerfile
.dockerignore
README.md
.git
.gitignore
versione.sh
_sitehub
```

**`.gitignore`**

```
.DS_Store
```

**`versione.sh`**: aggiorna il numero `?v=` di CSS e JS in tutte le pagine, così i visitatori ricevono subito i file nuovi.
Rendilo eseguibile (`chmod +x versione.sh`) e lancialo **dopo ogni modifica a `style.css` o `script.js`, prima del commit**.

```sh
#!/bin/sh
# Aggiorna la versione di style.css e script.js nelle pagine (style.css?v=…).
# Da lanciare dopo ogni modifica a CSS o JS, prima del commit: così browser e Cloudflare
# scaricano subito i file nuovi invece di usare quelli in cache.
cd "$(dirname "$0")"
css=$(shasum style.css | cut -c1-8)
js=$(shasum script.js | cut -c1-8)
find . -name '*.html' -not -path './.git/*' | while read -r f; do
  sed -i.bak -E "s#/style\.css(\?v=[a-f0-9]+)?\"#/style.css?v=$css\"#; s#/script\.js(\?v=[a-f0-9]+)?\"#/script.js?v=$js\"#" "$f" && rm "$f.bak"
done
echo "style.css?v=$css  script.js?v=$js"
```

**`README.md`**: poche righe su come è fatto il sito (file principali, come aggiornare la versione, come si pubblica).

---

## 14. Messa online

1. Repo su GitHub (uno per sito), ramo `main`.
2. Coolify: nuova applicazione dal repo, build pack **Dockerfile**, porta **80**, dominio del cliente (con e senza `www`).
3. Verifica che **Auto Deploy** sia attivo: ogni push su `main` (e ogni articolo pubblicato da SiteHub) va online da solo.
<!-- se:generica -->
4. Registra il sito su SiteHub dal gestionale e inserisci lo script con la chiave (sezione 12).
<!-- /se -->
<!-- se:sito -->
4. Il sito è già registrato su SiteHub: lo script della sezione 12 è quello giusto.
<!-- /se -->
<!-- se:form -->
5. Prova del sito su un dominio provvisorio (es. `cliente.planstudios.it`): aggiungilo ai "Domini di prova" del sito nel gestionale,
   altrimenti i form da quel dominio vengono rifiutati.
<!-- /se -->

---

## 15. Da non fare

- Framework, librerie, CDN, file da compilare, npm.
- Google Fonts, Adobe Fonts, Font Awesome o altre risorse caricate da altri domini.
- Banner cookie propri o di terzi (Iubenda, Cookiebot, CookieYes, plugin…).
<!-- se:consenso -->
- Script che tracciano o mappe/video incorporati senza `data-ssg-consent`.
<!-- /se -->
<!-- se:tecnici -->
- Script che tracciano, mappe o video incorporati (il sito usa solo cookie tecnici).
<!-- /se -->
<!-- se:form -->
- Form con `action`, JavaScript proprio per l'invio, servizi esterni per i form, captcha.
<!-- /se -->
<!-- se:no-form -->
- Form di contatto (il modulo form non è attivo) e servizi esterni per i form.
<!-- /se -->
- Pagine `.html` nella radice (`chi-siamo.html`): sempre `chi-siamo/index.html`.
- Link relativi (`../`, `immagini/foto.jpg`): sempre da `/`.
<!-- se:blog -->
- Pagine di articoli del blog scritte a mano, o file dentro `/blog/`.
<!-- /se -->
- Togliere `_sitehub` da `Dockerfile`, `.dockerignore` o `nginx.conf`: se il sito ha il blog, contiene anche le bozze degli articoli.
- Testi segnaposto (lorem ipsum) o dati inventati (indirizzi, P.IVA, recensioni) lasciati nel sito pubblicato.
- Immagini pesanti (oltre 400 KB) o senza `width`, `height` e `alt`.

---

## 16. Controlli finali

Prima di consegnare il sito:

- [ ] Ogni pagina si apre al suo indirizzo con la barra finale (`/chi-siamo/`) e nessun link porta a una 404.
- [ ] Un indirizzo inesistente mostra la `404.html` con header e footer.
- [ ] Title, description e canonical diversi e corretti in ogni pagina; un solo `h1` per pagina.
- [ ] `sitemap.xml` con tutte le pagine; `robots.txt` con il dominio giusto.
- [ ] Il sito funziona a 375, 768, 1366 e 1920 px, senza scroll orizzontale.
- [ ] Immagini in WebP, con `alt`, `width`, `height`; `loading="lazy"` sotto la prima schermata.
- [ ] Font e immagini caricati solo dal sito (nessuna richiesta ad altri domini, tranne SiteHub e i servizi con consenso).
- [ ] Menu mobile e form usabili da tastiera.
- [ ] Script di SiteHub in tutte le pagine, identico, prima di `</body>`.
<!-- se:form -->
- [ ] Form con `data-ssg-form`, senza `action`, con label e casella privacy; invio di prova riuscito.
<!-- /se -->
<!-- se:generica -->
- [ ] Footer con P.IVA, Privacy Policy, Cookie Policy (e Preferenze cookie se servono) in tutte le pagine.
<!-- /se -->
<!-- se:sito-consenso -->
- [ ] Footer con P.IVA, Privacy Policy, Cookie Policy e Preferenze cookie in tutte le pagine; script e mappe con `data-ssg-consent`.
<!-- /se -->
<!-- se:tecnici -->
- [ ] Footer con P.IVA, Privacy Policy e Cookie Policy in tutte le pagine; nessuna richiesta ad altri domini tranne SiteHub.
<!-- /se -->
- [ ] `./versione.sh` lanciato dopo l'ultima modifica a CSS o JS.
- [ ] `Dockerfile`, `nginx.conf`, `.dockerignore` presenti, con le righe per `_sitehub`.
<!-- se:blog -->
- [ ] Blog: `_sitehub/templates/articolo.html` e `blog.html` con header e footer aggiornati, voce "Blog" nel menu,
      `sitemap-blog.xml` in `robots.txt`, stile degli articoli in `style.css`.
<!-- /se -->

---

## 17. Esempio di richiesta a un LLM

<!-- se:generica -->
> Realizza il sito statico di **[nome azienda]** seguendo tutte le regole della guida allegata (GUIDA-SITI.md).
>
> - Pagine: home, chi siamo, servizi, contatti, privacy policy, cookie policy, 404.
> - Testi: [incolla i testi o allega il documento].
> - Grafica: colori [#…], font [nome, file allegati], stile [descrizione o riferimenti].
> - Immagini: [allegate / da indicare con segnaposto e dimensioni].
> - Dominio: https://www.[dominio].it
> - Servizi esterni: [nessuno / Google Analytics G-XXXX / mappa di Google Maps…]
> - Blog: [no / sì: crea i template come da sezione 12]
>
> Restituisci tutti i file con la struttura di cartelle della guida, compresi Dockerfile, nginx.conf, .dockerignore, versione.sh e README.md.
> Alla fine elenca le pagine create e i controlli della sezione 16 che hai verificato.

Quando il sito è registrato su SiteHub, usa la guida del sito al posto di questa.
<!-- /se -->
<!-- se:sito -->
Sito nuovo:

> Realizza il sito statico di **%NOME_SITO%** (%DOMINIO%) seguendo tutte le regole della guida allegata.
>
> - Pagine: home, chi siamo, servizi, contatti, privacy policy, cookie policy, 404.
> - Testi: [incolla i testi o allega il documento].
> - Grafica: colori [#…], font [nome, file allegati], stile [descrizione o riferimenti].
> - Immagini: [allegate / da indicare con segnaposto e dimensioni].
>
> Restituisci tutti i file con la struttura di cartelle della guida, compresi Dockerfile, nginx.conf, .dockerignore, versione.sh e README.md.
> Alla fine elenca le pagine create e i controlli della sezione 16 che hai verificato.

Sito esistente (es. dopo aver attivato un modulo nel gestionale):

> Ti allego i file del sito **%NOME_SITO%** e la sua guida. Applica le regole della guida che il sito non rispetta ancora,
> senza cambiare grafica, testi e struttura delle pagine. Alla fine elenca i file modificati e cosa hai cambiato.
<!-- /se -->

# Starter per i siti statici (Plan Studios + SiteHub)

Punto di partenza per ogni sito nuovo: struttura, pubblicazione su Coolify, SEO e collegamento a SiteHub
(form, banner cookie, blog) sono già pronti. Si cambiano solo testi, immagini, colori e font.

Le regole complete sono nella guida: https://hub.planstudios.it/guida.md
(per un sito già registrato su SiteHub usa la **guida del sito**, dal gestionale).

## Cosa c'è

- `index.html` – home a una pagina: hero, chi siamo, galleria, servizi, contatti con il form
- `privacy-policy/`, `cookie-policy/` – testi di base da adattare e far verificare al cliente
- `404.html` – pagina di errore (stesso header e footer)
- `style.css` – tutto lo stile; colori e font nelle variabili in cima (`--primario`, `--scuro`…)
- `script.js` – menu mobile e galleria con ingrandimento
- `assets/` – font (Montserrat, licenza OFL) e immagini segnaposto in SVG da sostituire
- `_sitehub/templates/` – template del blog (articolo ed elenco), letti da SiteHub
- `robots.txt`, `sitemap.xml` – per i motori di ricerca
- `Dockerfile`, `nginx.conf`, `.dockerignore` – pubblicazione su Coolify (build pack Dockerfile, porta 80)
- `versione.sh` – aggiorna la versione di CSS e JS nei link (lancialo dopo ogni modifica a `style.css` o `script.js`)

## Nuovo sito in 8 passi

1. **Copia** questa cartella con il nome del sito (senza la cartella `.git`) e crea un repo nuovo:
   `cp -R sito-starter nome-sito && cd nome-sito && rm -rf .git && git init`
2. **Sostituisci i segnaposto** in tutti i file (cerca e sostituisci):

   | Segnaposto | Con |
   |---|---|
   | `Nome Azienda` | nome del cliente |
   | `www.dominio-cliente.it`, `dominio-cliente.it` | dominio vero (con o senza www, come sarà online) |
   | `info@dominio-cliente.it` | email del cliente |
   | `+39 000 000 0000`, `+390000000000`, `390000000000` | telefono (scritto per esteso, nei link `tel:` e in WhatsApp) |
   | `Via Esempio 1, 00000 Città (XX)` | indirizzo |
   | `P.IVA 00000000000`, `IT00000000000` | partita IVA |
   | `Ragione sociale` | ragione sociale (pagine privacy e cookie) |
   | `GG mese AAAA` | data di aggiornamento delle pagine legali |

   Poi i testi: titoli, "Servizio 1…4", descrizioni, `title` e `meta description` di ogni pagina, dati strutturati in `index.html`.
3. **Grafica**: colori e font nelle variabili in cima a `style.css`. Per un altro font metti i file `.woff2` in `assets/fonts/`
   e aggiorna `@font-face` e il `preload` nelle pagine.
4. **Immagini**: sostituisci i segnaposto SVG con WebP vere (logo, favicon, hero 1920×800, chi siamo, galleria)
   e aggiorna nomi, `alt`, `width` e `height` nelle pagine. Due eccezioni, per compatibilità: `condivisione.jpg` (1200×630, JPG,
   anteprima dei link su WhatsApp e social) e `apple-touch-icon.png` (180×180, PNG, icona su iPhone).
   Per cambiare un'immagine in seguito usa un nome nuovo: le immagini restano in cache fino a 30 giorni.
   Galleria o WhatsApp non servono? Elimina le sezioni.
5. **Pagine in più**: una cartella per pagina (`servizi/index.html`), con lo stesso header e footer; aggiungile a menu e `sitemap.xml`.
6. **SiteHub**: registra il sito dal gestionale, poi sostituisci il commento `<!-- script SiteHub … -->` in **tutte** le pagine
   e nei due template con la riga dello script del sito.
7. **Blog**: la voce "Blog" di menu e footer (`data-ssg-modulo="blog" hidden`) si vede solo se il blog è attivo nel gestionale,
   quindi si può lasciare anche ai siti che per ora non ce l'hanno. Se il sito non avrà mai il blog, elimina `_sitehub/`, quella voce
   e la riga commentata di `sitemap-blog.xml` in `robots.txt`. Se lo ha, dopo il primo deploy collega il blog dal gestionale
   (pagina del sito → Blog → Collega il blog) e togli il `#` dalla riga di `sitemap-blog.xml` in `robots.txt`.
8. **Prima del commit**: `./versione.sh`, poi i controlli finali della guida (sezione 16). Repo su GitHub e app su Coolify.

## SEO (prima di andare online)

Le regole complete sono nella guida, sezione SEO. In breve:

- [ ] `title` (50-60 caratteri, "Argomento – Nome Azienda") e `description` (140-155) **diversi per ogni pagina**; un solo `h1` per pagina
- [ ] una pagina per ogni servizio importante, con testi veri (almeno 250-300 parole) e la città dove ha senso
- [ ] nome, indirizzo e telefono **identici** in footer, contatti, dati strutturati e scheda Google; tipo di dati strutturati adatto all'attività
- [ ] immagini WebP con nomi parlanti e `alt`; l'immagine dell'hero senza `loading="lazy"`
- [ ] `sitemap.xml` con tutte le pagine; se il sito sostituisce un vecchio sito, redirect 301 in `nginx.conf`
- [ ] dopo la messa online: PageSpeed Insights da mobile verde, test dei risultati multimediali, sitemap inviata a Search Console

Search Console e statistiche sono **facoltative** e si scelgono nel gestionale (pagina del sito → SEO e statistiche):
la guida del sito dice poi esattamente cosa aggiungere (nel `<head>` c'è già il commento che indica dove).
Con Google Analytics serve il consenso: lo script si scrive con `data-ssg-consent="statistics"` e la cookie policy va aggiornata.

## Con un LLM

Dai all'LLM la cartella (o i file) dello starter insieme alla guida del sito e a testi, colori e immagini del cliente:

> Partendo dallo starter allegato e seguendo la guida, realizza il sito di **[nome]**. Mantieni struttura, file di pubblicazione,
> form e template del blog; sostituisci segnaposto, testi, colori e immagini. Alla fine elenca i file modificati.

## Cookie

Lo starter usa solo cookie tecnici: niente banner. Se il sito userà Analytics, pixel, mappe o video incorporati,
segui la sezione Cookie della guida (`data-ssg-consent`), aggiorna la cookie policy e attiva nel footer il link "Preferenze cookie"
(è già scritto, commentato).

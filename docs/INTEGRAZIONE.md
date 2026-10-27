# Collegare un sito HTML a SiteHub

Questa guida è per chi realizza l'HTML di un sito, a mano o con un LLM.
L'HTML può essere fatto come si preferisce: i moduli del pannello (form, cookie, blog) si adattano.
Servono solo le poche convenzioni descritte qui.

> **Per gli LLM:** questo documento può essere incluso così com'è nelle istruzioni di generazione del sito.
>
> La guida completa per realizzare un sito (struttura, SEO, pubblicazione e queste stesse regole) è [GUIDA-SITI.md](GUIDA-SITI.md),
> pubblicata anche su https://hub.planstudios.it/guida.md.

## 1. Lo script del pannello (obbligatorio)

Una riga, prima di `</body>`, uguale su **tutte** le pagine:

```html
<script src="https://hub.planstudios.it/e/v1/pk_XXXXXXXX.js" defer></script>
```

La riga esatta, con la chiave `pk_…` del sito, si trova nel pannello alla voce del sito (campo `embed.snippet`).
La chiave è pubblica: può stare nell'HTML senza problemi.

Lo script carica solo i moduli attivi per quel sito. Attivare o disattivare un modulo dal pannello non richiede modifiche all'HTML.

## 2. Form di contatto

Basta aggiungere `data-ssg-form` al tag `<form>`, con un nome che identifica il modulo:

```html
<form data-ssg-form="contatti">
  <label for="nome">Nome</label>
  <input id="nome" name="nome" required>

  <label for="email">Email</label>
  <input id="email" name="email" type="email" required>

  <label for="msg">Messaggio</label>
  <textarea id="msg" name="messaggio"></textarea>

  <label><input type="checkbox" name="privacy" required> Ho letto la <a href="/privacy-policy/">privacy policy</a></label>

  <button type="submit">Invia</button>
</form>
```

Non serve altro: niente `action`, niente JavaScript, niente configurazione. Al primo invio il modulo compare nel pannello, dove si impostano destinatari, oggetto e risposta automatica.

**Cosa fa il modulo da solo:**
- ricava l'etichetta di ogni campo da `<label>`; se manca, usa `aria-label`, poi il `placeholder`, poi il `name`;
- usa il campo `type="email"`, o quello che si chiama "email", come "Rispondi a" nell'email al cliente;
- aggiunge la propria protezione antispam e ignora i campi nascosti del tema;
- disattiva il pulsante durante l'invio e mostra un messaggio di conferma o di errore;
- se il visitatore ha JavaScript disattivato, il form viene inviato comunque.

**Buone pratiche** (consigliate, non obbligatorie):
- usate sempre `<label>`: è meglio per l'accessibilità e dà etichette pulite nelle email;
- usate `type="email"` per l'email e `type="tel"` per il telefono;
- usate `required` per i campi obbligatori, la validazione la fa il browser;
- se ci sono più form sulla stessa pagina o sullo stesso sito, date nomi diversi (`contatti`, `preventivo`, `lavora-con-noi`);
- per un gruppo di checkbox o radio usate `<fieldset>` con `<legend>`: la legenda diventa l'etichetta nell'email.

**Personalizzazioni facoltative:**

| Attributo / elemento | Effetto |
|---|---|
| `<div data-ssg-success hidden>Grazie!</div>` dentro il form | messaggio di conferma personalizzato, al posto di quello automatico |
| `<div data-ssg-error hidden>Ops…</div>` dentro il form | messaggio di errore personalizzato |
| `data-ssg-redirect="/grazie/"` sul `<form>` | dopo l'invio porta a una pagina di ringraziamento |
| evento `ssg:success` / `ssg:error` sul form | per agganciare codice proprio, per esempio una conversione analytics |

```js
document.querySelector('form[data-ssg-form="contatti"]')
  .addEventListener('ssg:success', () => gtag('event', 'generate_lead'));
```

**Da evitare:**
- campi `type="file"` (allegati): non sono supportati e vengono ignorati;
- campi `type="password"`: vengono ignorati;
- servizi esterni per i form (Formspree, Google Forms…) sui siti gestiti dal pannello.

## 3. Cookie e script che tracciano

Con il modulo "Banner cookie" attivo, SiteHub mostra il banner di consenso e fa partire gli script che tracciano solo dopo il consenso.
Il banner compare **solo se il sito usa cookie che richiedono consenso**. Un sito con soli cookie tecnici non mostra niente.

**Script che tracciano** (Google Analytics, Meta Pixel, Hotjar…): stesso codice di sempre, ma con `type="text/plain"` e la categoria in `data-ssg-consent`:

```html
<script type="text/plain" data-ssg-consent="statistics" src="https://www.googletagmanager.com/gtag/js?id=G-XXXX" async></script>
<script type="text/plain" data-ssg-consent="statistics">
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', 'G-XXXX');
</script>
```

Categorie: `statistics` (statistiche, analytics) e `marketing` (pubblicità, pixel, remarketing).

**Mappe, video e altri contenuti esterni** (Google Maps, YouTube): `data-ssg-src` al posto di `src`, più la categoria. Finché manca il consenso, al loro posto compare un avviso con il pulsante "Consenti e mostra".

```html
<iframe data-ssg-consent="marketing" data-ssg-src="https://www.google.com/maps/embed?pb=…" width="600" height="400" style="border:0" loading="lazy"></iframe>
```

**Link per cambiare le preferenze** (consigliato), di solito nel footer:

```html
<a href="#cookie-settings">Preferenze cookie</a>
```

Va bene anche qualunque elemento con `data-ssg-cookie-settings`. Funziona anche da un'altra pagina, per esempio nella cookie policy: `<a href="/#cookie-settings">cambia le preferenze</a>` apre la home con le preferenze aperte.
Se la pagina non ha nessuno di questi link, dopo la scelta compare un piccolo pulsante fisso in un angolo che riapre le preferenze. L'angolo (sinistra o destra) o la sua disattivazione si impostano nel pannello.

**Cosa fa il modulo da solo:**
- pulsanti "Rifiuta", "Personalizza", "Accetta tutti" con lo stesso peso; la X chiude rifiutando;
- ricorda la scelta per 6 mesi; dal pannello si può chiedere di nuovo il consenso a tutti;
- se il visitatore revoca un consenso, cancella i cookie dei tracker noti e ricarica la pagina;
- se il sito usa `gtag`, aggiorna Google Consent Mode v2; invia l'evento `ssg:consent` sul `document`;
- banner isolato dallo stile del sito (shadow DOM). Per usare il font del sito: `:root { --ssg-font: "Montserrat", sans-serif; }`.

Nel pannello si impostano il link alla cookie policy (predefinito `/cookie-policy/`), il colore dei pulsanti, un testo personalizzato, la posizione del pulsante fisso e le categorie da chiedere anche se non compaiono nella pagina, per esempio quelle usate da un Google Tag Manager.

**Da evitare:**
- script che tracciano scritti normalmente, senza `type="text/plain"`: partono prima del consenso;
- altri banner cookie (Iubenda, Cookiebot, plugin) insieme a questo.

## 4. Domini di prova

I form sono accettati solo dal dominio del sito, con e senza `www`. Per provare il sito su un altro indirizzo, per esempio l'anteprima di Coolify o uno staging, aggiungete quel dominio nel pannello alla voce `origins` del sito.

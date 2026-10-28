# Editor dei siti statici

Servizio che modifica e crea i siti statici con Claude (Claude Agent SDK). Lo usa il gestionale:
"Modifica sito" (chat sul repo di un sito) e "Nuovo sito" (repo creato dallo starter, poi chat).

## Come funziona

- **Sessione**: il repo del sito viene scaricato in una cartella di lavoro; Claude legge e modifica i file
  seguendo la guida del sito (SiteHub) e alcune regole fisse; si guarda l'anteprima; si pubblica (commit e push su `main`,
  o su un altro ramo se richiesto) oppure si annulla.
- **Sicurezza**:
  - Claude ha solo gli strumenti per i file (`Read`, `Write`, `Edit`, `Glob`, `Grep`): niente terminale né internet.
  - Ogni operazione passa da una regola di permesso: solo dentro la cartella del sito, mai `.git`,
    mai i file generati da SiteHub (`blog/`, `sitemap-blog.xml`, `_sitehub/content/`, `assets/blog/`).
  - git, versione di CSS/JS e pubblicazione li fa il servizio, in passi fissi.
  - Il token GitHub viaggia solo nelle chiamate a GitHub: non finisce nei file né nell'ambiente di Claude.
  - La chiave Anthropic e il modello li manda il gestionale a ogni messaggio (impostazioni AI): il servizio non li conserva.
- **Sito nuovo**: repo privato nell'organizzazione, contenuto di `../starter/` come primo commit, con la riga dello script
  di SiteHub già inserita; poi Claude lo completa a partire dal brief.
- **Anteprima**: `https://…/anteprima/{chiave}/`, chiave casuale per sessione, non indicizzata.

API: vedi l'intestazione di `src/server.js`.

## Su Coolify

- Nuova applicazione dal repo di SiteHub, build pack **Dockerfile**, Dockerfile `editor/Dockerfile`,
  contesto di build: la radice del repo. Porta 80. Dominio, es. `editor.planstudios.it`.
- Volume persistente su `/data`.
- Variabili d'ambiente:

| Variabile | Valore |
|---|---|
| `EDITOR_TOKEN` | chiave lunga e casuale (`openssl rand -hex 32`), la stessa da inserire nel gestionale |
| `GITHUB_TOKEN` | token GitHub dedicato all'editor (fine-grained, proprietario dell'organizzazione dei siti): **Contents** e **Administration** in lettura e scrittura, su tutti i repo |
| `GITHUB_ORG` | `Plan-Studios` |
| `PUBLIC_URL` | indirizzo pubblico, es. `https://editor.planstudios.it` |
| `EDITOR_MAX_BUDGET_USD` | facoltativo: tetto di spesa per ogni messaggio (predefinito 5) |

## Sviluppo

```sh
cd editor && npm install && npm test   # finto Claude e finto GitHub: niente costi, niente rete
```

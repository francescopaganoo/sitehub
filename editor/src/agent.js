// Claude sul sito: Claude Agent SDK con i soli strumenti per i file, dentro la cartella di lavoro.
// Niente terminale né rete: git, versione di CSS/JS e pubblicazione li fa il servizio, in passi fissi.

import path from 'node:path';
import fs from 'node:fs/promises';
import { query } from '@anthropic-ai/claude-agent-sdk';
import { config } from './config.js';

export const TOOLS = ['Read', 'Write', 'Edit', 'Glob', 'Grep'];
const BLOCKED_TOOLS = ['Bash', 'WebFetch', 'WebSearch', 'Agent', 'Task', 'NotebookEdit', 'Workflow'];

/** File generati da SiteHub (blog) o interni a git: Claude non li modifica. */
const PROTECTED = [/^\.git(\/|$)/, /^blog(\/|$)/, /^sitemap-blog\.xml$/, /^_sitehub\/content(\/|$)/, /^assets\/blog(\/|$)/];

/** Percorso relativo alla cartella del sito, null se è fuori. */
export function insideSite(root, filePath) {
  const resolved = path.resolve(root, filePath || '.');
  const rel = path.relative(root, resolved);
  if (rel.startsWith('..') || path.isAbsolute(rel)) return null;
  return rel.split(path.sep).join('/');
}

/** Decide ogni uso di uno strumento: dentro la cartella sì, file protetti no. */
export function permission(root, toolName, input) {
  if (!TOOLS.includes(toolName)) {
    return { behavior: 'deny', message: `Strumento non disponibile in questo editor: ${toolName}` };
  }
  const target = input.file_path ?? input.path ?? '.';
  const rel = insideSite(root, target);
  if (rel === null) {
    return { behavior: 'deny', message: 'Puoi lavorare solo sui file del sito, nella cartella corrente.' };
  }
  if (rel === '.git' || rel.startsWith('.git/')) {
    return { behavior: 'deny', message: 'La cartella .git non si legge né si modifica.' };
  }
  if ((toolName === 'Write' || toolName === 'Edit') && PROTECTED.some((re) => re.test(rel))) {
    return { behavior: 'deny', message: `${rel} è generato da SiteHub (blog): non si modifica a mano. Per gli articoli c'è il gestionale; per la grafica del blog modifica _sitehub/templates/.` };
  }
  return { behavior: 'allow', updatedInput: input };
}

export function rules(meta) {
  return `Stai lavorando sul sito statico «${meta.siteName}» (${meta.domain}): una cartella di file HTML, CSS e JavaScript.
Ti scrive una persona dell'agenzia Plan Studios, non necessariamente una programmatrice.

Come rispondere:
- In italiano, in modo breve e chiaro. Alla fine di ogni richiesta riassumi cosa hai cambiato, con parole semplici, e in quali pagine.
- Se la richiesta è ambigua o rischiosa (cancellare pagine, cambiare indirizzi, togliere contenuti), chiedi conferma prima di procedere.

Regole fisse, che valgono sempre:
- Segui la guida del sito riportata sotto: struttura, SEO, collegamento a SiteHub.
- Header, footer e <head> sono ripetuti in ogni pagina: se li cambi, cambiali in TUTTE le pagine, compresa 404.html${meta.hasTemplates ? ' e i due template del blog in _sitehub/templates/' : ''}.
- Non modificare blog/, sitemap-blog.xml, _sitehub/content/ e assets/blog/: li genera SiteHub per il blog.
- Non aggiornare a mano il numero ?v= di style.css e script.js: lo fa il sistema quando si pubblica.
- Testi veri: non inventare dati dell'azienda (indirizzi, P.IVA, recensioni, numeri). Se mancano, chiedili.
- Hai solo gli strumenti per leggere, cercare e modificare i file del sito: niente comandi, niente internet.
- Non pubblichi tu: quando hai finito, la persona guarda l'anteprima e decide se pubblicare.

=== GUIDA DEL SITO ===
${meta.guide || '(guida non disponibile: segui la struttura dei file esistenti)'}`;
}

/**
 * Un messaggio della chat. Restituisce un iteratore di eventi per il gestionale:
 *   {type: 'text', text}                testo di Claude, a pezzi, mentre scrive
 *   {type: 'tool', tool, target}        file letto, cercato o modificato
 *   {type: 'denied', tool, message}     operazione rifiutata dalle regole
 *   {type: 'done', ...}                 fine: costo stimato, turni, sessione Claude da riprendere
 */
export async function* runMessage(meta, root, text, { apiKey, model, effort, signal }) {
  if (config.fakeAgent) {
    yield* fakeRun(root, text);
    return;
  }
  const denied = [];
  const abortController = new AbortController();
  signal?.addEventListener('abort', () => abortController.abort());

  const stream = query({
    prompt: text,
    options: {
      cwd: root,
      model: model || 'claude-opus-5-5',
      effort: effort || 'high',
      tools: TOOLS,
      disallowedTools: BLOCKED_TOOLS,
      permissionMode: 'default',
      canUseTool: async (toolName, input) => {
        const decision = permission(root, toolName, input);
        if (decision.behavior === 'deny') denied.push({ tool: toolName, message: decision.message });
        return decision;
      },
      systemPrompt: { type: 'preset', preset: 'claude_code', append: rules(meta) },
      settingSources: [],
      includePartialMessages: true,
      maxTurns: 60,
      maxBudgetUsd: config.maxBudgetUsd,
      resume: meta.claudeSession || undefined,
      abortController,
      // ambiente minimo: niente token GitHub o altre variabili del servizio nel processo di Claude
      env: { PATH: process.env.PATH, HOME: process.env.HOME || '/tmp', ANTHROPIC_API_KEY: apiKey },
    },
  });

  let reported = 0;
  let finished = false;
  try {
    for await (const message of stream) {
      if (message.type === 'stream_event' && message.event?.type === 'content_block_delta' && message.event.delta?.type === 'text_delta') {
        yield { type: 'text', text: message.event.delta.text };
      } else if (message.type === 'assistant') {
        for (const block of message.message?.content || []) {
          if (block.type === 'tool_use') {
            yield { type: 'tool', tool: block.name, target: insideSite(root, block.input?.file_path ?? block.input?.path ?? '.') ?? '(fuori dal sito)', pattern: block.input?.pattern };
          }
        }
        while (reported < denied.length) yield { type: 'denied', ...denied[reported++] };
      } else if (message.type === 'result') {
        while (reported < denied.length) yield { type: 'denied', ...denied[reported++] };
        const failed = message.subtype !== 'success' || message.is_error;
        finished = true;
        yield {
          type: 'done',
          ok: !failed,
          error: failed ? explain(message.subtype === 'success' ? message.result : message.subtype) : null,
          claudeSession: message.session_id,
          costUsd: message.total_cost_usd,
          turns: message.num_turns,
        };
      }
    }
  } catch (e) {
    // dopo un risultato con errore il SDK solleva anche un'eccezione: l'esito è già stato dato
    if (!finished) throw new Error(explain(e.message));
  }
}

/** Errori del SDK in parole comprensibili per chi usa il gestionale. */
export function explain(raw) {
  const text = String(raw || '');
  if (/invalid api key|authentication|401/i.test(text)) return 'Chiave Anthropic non valida: controllala nelle impostazioni AI del gestionale.';
  if (/credit balance|billing|402/i.test(text)) return 'Credito Anthropic esaurito: ricaricalo sulla console di Anthropic.';
  if (/rate.?limit|429|overloaded|529/i.test(text)) return 'Claude è sovraccarico o il limite di richieste è stato raggiunto: riprova tra qualche minuto.';
  if (text === 'error_max_budget_usd') return 'Raggiunto il tetto di spesa per questa richiesta: dividila in richieste più piccole.';
  if (text === 'error_max_turns') return 'La richiesta era troppo lunga da completare in una volta: dividila in passi più piccoli.';
  return text || 'Errore sconosciuto';
}

/**
 * Finto agente per i test (EDITOR_FAKE_AGENT=1): niente Claude, stesse regole di permesso.
 * "sostituisci A con B" cambia il testo in tutte le pagine; "modifica blog" prova a toccare un file protetto.
 */
async function* fakeRun(root, text) {
  const replace = text.match(/sostituisci «(.+?)» con «(.+?)»/);
  if (replace) {
    yield { type: 'text', text: 'Cerco il testo nelle pagine… ' };
    for (const file of await htmlFiles(root)) {
      const content = await fs.readFile(path.join(root, file), 'utf8');
      if (!content.includes(replace[1])) continue;
      const decision = permission(root, 'Edit', { file_path: path.join(root, file) });
      if (decision.behavior === 'deny') {
        yield { type: 'denied', tool: 'Edit', message: decision.message };
        continue;
      }
      yield { type: 'tool', tool: 'Edit', target: file };
      await fs.writeFile(path.join(root, file), content.replaceAll(replace[1], replace[2]));
    }
    yield { type: 'text', text: `Fatto: «${replace[1]}» ora è «${replace[2]}».` };
  }
  if (/modifica blog/.test(text)) {
    const decision = permission(root, 'Write', { file_path: 'blog/index.html' });
    yield { type: 'denied', tool: 'Write', message: decision.message };
  }
  yield { type: 'done', ok: true, error: null, claudeSession: 'finta-sessione', costUsd: 0, turns: 1 };
}

async function htmlFiles(root, dir = '') {
  const out = [];
  for (const entry of await fs.readdir(path.join(root, dir), { withFileTypes: true })) {
    const rel = dir ? `${dir}/${entry.name}` : entry.name;
    if (entry.name === '.git') continue;
    if (entry.isDirectory()) out.push(...await htmlFiles(root, rel));
    else if (entry.name.endsWith('.html')) out.push(rel);
  }
  return out;
}

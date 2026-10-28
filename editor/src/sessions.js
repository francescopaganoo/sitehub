// Sessioni di modifica: una per sito e lavoro (cartella di lavoro + chat con Claude + anteprima).
// I dati stanno su disco (DATA_DIR) e sopravvivono ai riavvii del servizio.

import fs from 'node:fs/promises';
import crypto from 'node:crypto';
import path from 'node:path';
import { config, workDir, metaFile } from './config.js';
import * as git from './git.js';
import { runMessage, isSiteHubFile } from './agent.js';
import { stampVersions, prepareFromStarter } from './site.js';

export class SessionError extends Error {
  constructor(status, message) {
    super(message);
    this.status = status;
  }
}

const running = new Map(); // id sessione → AbortController del messaggio in corso

// proprietario/nome, senza parti che iniziano con un punto (niente «..»: il nome finisce anche in percorsi su disco)
const REPO_RE = /^[\w-][\w.-]*\/[\w-][\w.-]*$/;

const newId = () => crypto.randomBytes(8).toString('hex');

async function save(meta) {
  meta.updatedAt = new Date().toISOString();
  await fs.mkdir(path.dirname(metaFile(meta.id)), { recursive: true });
  await fs.writeFile(metaFile(meta.id), JSON.stringify(meta, null, 2));
  return meta;
}

export async function get(id) {
  if (!/^[a-f0-9]{16}$/.test(id)) throw new SessionError(404, 'Sessione non trovata');
  try {
    return JSON.parse(await fs.readFile(metaFile(id), 'utf8'));
  } catch {
    throw new SessionError(404, 'Sessione non trovata');
  }
}

/** Sessioni, filtrate per sito (slug del gestionale) o per repo. */
export async function list({ slug, repo } = {}) {
  const dir = path.join(config.dataDir, 'sessions');
  const files = await fs.readdir(dir).catch(() => []);
  const all = await Promise.all(files.filter((f) => f.endsWith('.json')).map((f) => fs.readFile(path.join(dir, f), 'utf8').then(JSON.parse).catch(() => null)));
  return all.filter((m) => m && (!repo || m.repo === repo) && (!slug || m.slug === slug)).sort((a, b) => b.updatedAt.localeCompare(a.updatedAt));
}

async function fetchGuide(url) {
  if (!url) return '';
  try {
    const res = await fetch(url, { signal: AbortSignal.timeout(15000) });
    return res.ok ? await res.text() : '';
  } catch {
    return '';
  }
}

const hasTemplates = (root) => fs.access(path.join(root, '_sitehub/templates')).then(() => true, () => false);

function base({ kind, slug, repo, branch, siteName, domain, guideUrl, user }) {
  return {
    id: newId(), kind, slug: slug || null, repo, branch: branch || 'main', siteName, domain, guideUrl,
    previewKey: crypto.randomBytes(16).toString('hex'),
    claudeSession: null, costUsd: 0, history: [], published: [],
    createdBy: user, createdAt: new Date().toISOString(),
  };
}

/** Modifica di un sito esistente: si scarica il repo. */
export async function create({ slug, repo, branch, siteName, domain, guideUrl, user }) {
  if (!REPO_RE.test(repo || '')) throw new SessionError(422, 'Repo non valido (proprietario/nome)');
  const meta = base({ kind: 'edit', slug, repo, branch, siteName, domain, guideUrl, user });
  const root = workDir(meta.id);
  await fs.mkdir(path.dirname(root), { recursive: true });
  try {
    await git.clone(repo, meta.branch, root);
  } catch (e) {
    throw new SessionError(502, `Download del repo non riuscito: ${e.message}`);
  }
  meta.guide = await fetchGuide(guideUrl);
  meta.hasTemplates = await hasTemplates(root);
  return save(meta);
}

/** Sito nuovo: repo creato su GitHub, starter come primo commit, poi si lavora come su un sito esistente. */
export async function createSite({ slug, repoName, description, siteName, domain, guideUrl, sitehubSnippet, user }) {
  if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(repoName || '')) throw new SessionError(422, 'Nome del repo: lettere minuscole, numeri e trattini');
  const repo = await git.createRepo(repoName, description || `Sito ${siteName}`).catch((e) => { throw new SessionError(422, e.message); });
  const meta = base({ kind: 'new', slug, repo, branch: 'main', siteName, domain, guideUrl, user });
  const root = workDir(meta.id);
  await fs.mkdir(root, { recursive: true });
  await prepareFromStarter(root, { sitehubSnippet });
  await stampVersions(root);
  await git.pushFirstCommit(root, repo, { message: `Sito ${siteName} creato dallo starter`, author: author(user) });
  meta.guide = await fetchGuide(guideUrl);
  meta.hasTemplates = true;
  return save(meta);
}

function author(user) {
  return { name: user?.name || 'Gestionale', email: user?.email || 'gestionale@planstudios.it' };
}

/**
 * Un messaggio della chat. `emit` riceve gli eventi man mano (testo, file toccati, fine).
 * Un messaggio alla volta per sessione.
 */
export async function message(id, { text, authMode = 'api', apiKey, oauthToken, model, effort, user }, emit) {
  const meta = await get(id);
  if (!text?.trim()) throw new SessionError(422, 'Messaggio vuoto');
  if (!['api', 'subscription'].includes(authMode)) throw new SessionError(422, 'Modalità Claude non valida');
  if (!config.fakeAgent) {
    if (authMode === 'subscription' && (typeof oauthToken !== 'string' || !/^sk-ant-oat\d+-[A-Za-z0-9_-]+$/.test(oauthToken))) {
      throw new SessionError(409, 'Token dell’abbonamento Claude non configurato o non valido nel gestionale');
    }
    if (authMode === 'api' && (typeof apiKey !== 'string' || !apiKey.trim())) {
      throw new SessionError(409, 'Chiave Anthropic non configurata nel gestionale');
    }
  }
  if (running.has(id)) throw new SessionError(409, 'Claude sta già lavorando su questa sessione');

  const root = workDir(id);
  const controller = new AbortController();
  running.set(id, controller);
  const turn = { role: 'user', text, by: user?.name || null, at: new Date().toISOString() };
  const answer = { role: 'assistant', text: '', events: [], at: null };
  meta.history.push(turn, answer);
  try {
    // novità dal repo (es. articoli pubblicati da SiteHub) prima di lavorare, se non ci sono modifiche in corso
    if (!(await git.changes(root)).length) await git.pull(root).catch(() => {});
    for await (const event of runMessage(meta, root, text, { authMode, apiKey, oauthToken, model, effort, signal: controller.signal })) {
      if (event.type === 'text') answer.text += event.text;
      else if (event.type === 'done') {
        meta.claudeSession = event.claudeSession || meta.claudeSession;
        meta.costUsd = Math.round(((meta.costUsd || 0) + (event.costUsd || 0)) * 10000) / 10000;
        event.sessionCostUsd = meta.costUsd;
        event.files = await git.changes(root);
        answer.events.push({ type: 'done', ok: event.ok, error: event.error, costUsd: event.costUsd });
      } else answer.events.push(event);
      emit(event);
    }
  } catch (e) {
    const failure = controller.signal.aborted ? 'Interrotto' : `Errore: ${e.message}`;
    answer.events.push({ type: 'error', message: failure });
    emit({ type: 'error', message: failure });
  } finally {
    answer.at = new Date().toISOString();
    running.delete(id);
    await save(meta);
  }
}

export function stop(id) {
  running.get(id)?.abort();
  return running.has(id);
}

export async function changes(id) {
  await get(id);
  return git.changes(workDir(id));
}

export async function diff(id) {
  await get(id);
  return git.diff(workDir(id));
}

/** Pubblica: versione di CSS/JS aggiornata, commit, push (su main, o sul ramo indicato). */
export async function publish(id, { message, branch, user }) {
  const meta = await get(id);
  if (running.has(id)) throw new SessionError(409, 'Aspetta che Claude finisca prima di pubblicare');
  const root = workDir(id);
  if (!(await git.changes(root)).length) throw new SessionError(409, 'Nessuna modifica da pubblicare');
  await stampVersions(root);
  const files = await git.changes(root);
  let sha;
  try {
    // il riferimento alla sessione serve allo storico per riconoscere le pubblicazioni fatte da qui
    const text = message || meta.revertOf?.message || `Modifiche dal gestionale (${user?.name || 'editor'})`;
    sha = await git.publish(root, { message: `${text}\n\nEditor-Session: ${id}`, author: author(user), branch });
  } catch (e) {
    throw new SessionError(502, `Pubblicazione non riuscita: ${e.message}`);
  }
  meta.published.push({ sha, branch: branch || meta.branch, at: new Date().toISOString(), by: user?.name || null, files: files.length });
  delete meta.revertOf;
  await save(meta);
  return { sha, branch: branch || meta.branch, files };
}

const IMAGE = /\.(webp|png|jpe?g|gif|svg|ico|avif)$/i;

/**
 * Immagine caricata nella sessione (logo, foto del cliente): solo in assets/, solo immagini.
 * Il nome viene ripulito (minuscole, trattini); se esiste già si aggiunge un numero.
 */
export async function upload(id, requested, data) {
  await get(id);
  const root = workDir(id);
  const clean = String(requested || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '')
    .split('/').map((part) => part.replace(/[^a-z0-9._-]+/g, '-').replace(/^-+|-+$/g, '')).filter(Boolean).join('/');
  if (!clean.startsWith('assets/') || !IMAGE.test(clean) || clean.includes('..') || clean.startsWith('assets/blog/')) {
    throw new SessionError(422, 'Si possono caricare solo immagini in assets/ (es. assets/img/logo.webp)');
  }
  if (!data?.length) throw new SessionError(422, 'File vuoto');
  if (data.length > 15 * 1024 * 1024) throw new SessionError(413, 'File troppo grande (massimo 15 MB)');
  let target = clean;
  for (let n = 2; await fs.access(path.join(root, target)).then(() => true, () => false); n++) {
    target = clean.replace(/(\.[a-z0-9]+)$/, `-${n}$1`);
  }
  await fs.mkdir(path.dirname(path.join(root, target)), { recursive: true });
  await fs.writeFile(path.join(root, target), data);
  return { path: target, bytes: data.length };
}

/** Annulla le modifiche non pubblicate (la chat resta). */
export async function discard(id) {
  await get(id);
  if (running.has(id)) throw new SessionError(409, 'Claude sta lavorando: interrompilo prima');
  await git.discardChanges(workDir(id));
  const meta = await get(id);
  if (meta.revertOf) {
    delete meta.revertOf;
    await save(meta);
  }
}

/**
 * Storico delle pubblicazioni di un sito. `source`: editor (pubblicato da qui), blog (articoli, da SiteHub),
 * altro (push fatti fuori dal gestionale). Il blog non si annulla da qui: lo gestisce SiteHub.
 */
export async function history({ repo, branch = 'main', limit = 30 }) {
  if (!REPO_RE.test(repo || '')) throw new SessionError(422, 'Repo non valido (proprietario/nome)');
  if (!/^[\w./-]{1,80}$/.test(branch) || branch.includes('..')) throw new SessionError(422, 'Ramo non valido');
  let commits;
  try {
    commits = await git.history(repo, branch, path.join(config.dataDir, 'mirrors', `${repo}.git`), Math.min(Number(limit) || 30, 100));
  } catch (e) {
    throw new SessionError(502, `Storico non disponibile: ${e.message}`);
  }
  return commits.map((c) => {
    const source = c.session ? 'editor' : c.files.length && c.files.every(isSiteHubFile) ? 'blog' : 'altro';
    return { ...c, source, revertable: source !== 'blog' && c.parents.length === 1 };
  });
}

/**
 * Prepara l'annullamento di una pubblicazione nella sessione: le modifiche si vedono nell'anteprima e nelle
 * differenze, e vanno online solo con Pubblica.
 */
export async function revert(id, { sha, subject, user }) {
  const meta = await get(id);
  if (!/^[0-9a-f]{7,40}$/.test(sha || '')) throw new SessionError(422, 'Pubblicazione non valida');
  if (running.has(id)) throw new SessionError(409, 'Claude sta lavorando: aspetta che finisca');
  const root = workDir(id);
  if ((await git.changes(root)).length) throw new SessionError(409, 'Ci sono modifiche non pubblicate in questa sessione: pubblicale o annullale prima');
  await git.pull(root).catch(() => {});
  const files = (await git.history(meta.repo, meta.branch, path.join(config.dataDir, 'mirrors', `${meta.repo}.git`), 100)
    .catch(() => [])).find((c) => c.sha.startsWith(sha))?.files || [];
  if (files.length && files.every(isSiteHubFile)) throw new SessionError(422, 'Gli articoli del blog si gestiscono dal gestionale, non da qui');
  try {
    await git.revert(root, sha);
  } catch (e) {
    if (e instanceof git.ConflictError) {
      throw new SessionError(409, 'Non si può annullare in automatico: nel frattempo sono cambiate le stesse parti del sito. '
        + 'Chiedi a Claude in chat di rimetterle com\'erano (es. «rimetti il footer com\'era prima»).');
    }
    throw new SessionError(422, e.message);
  }
  const title = subject ? `«${String(subject).slice(0, 120)}»` : sha.slice(0, 7);
  meta.revertOf = { sha, subject: subject || null, message: `Annulla ${title}` };
  meta.history.push({
    role: 'assistant', at: new Date().toISOString(), by: user?.name || null, events: [],
    text: `Ho preparato l'annullamento della pubblicazione ${title}. Controlla l'anteprima e le differenze, poi pubblica.`,
  });
  await save(meta);
  return { files: await git.changes(root) };
}

/** Chiude la sessione: cartella di lavoro e dati eliminati. */
export async function remove(id) {
  await get(id);
  stop(id);
  await fs.rm(workDir(id), { recursive: true, force: true });
  await fs.rm(metaFile(id), { force: true });
}

export async function byPreviewKey(key) {
  if (!/^[a-f0-9]{32}$/.test(key)) return null;
  return (await list()).find((m) => m.previewKey === key) || null;
}

/** Vista pubblica di una sessione (senza guida e chiave di anteprima ripetute). */
export function view(meta) {
  const { guide, previewKey, ...rest } = meta;
  return { ...rest, running: running.has(meta.id), previewPath: `/anteprima/${previewKey}/` };
}

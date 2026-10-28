// Operazioni git e GitHub. Il token GitHub viaggia solo nell'intestazione di ogni comando
// (http.extraHeader): non finisce in .git/config né in altri file della cartella di lavoro,
// che Claude può leggere.

import { execFile } from 'node:child_process';
import fs from 'node:fs/promises';
import path from 'node:path';
import { promisify } from 'node:util';
import { config } from './config.js';

const run = promisify(execFile);

export class GitError extends Error {}
export class ConflictError extends GitError {}

const authHeader = () => `AUTHORIZATION: basic ${Buffer.from(`x-access-token:${config.githubToken}`).toString('base64')}`;

async function git(cwd, args, { auth = false } = {}) {
  const pre = auth ? ['-c', `http.extraHeader=${authHeader()}`] : [];
  try {
    const { stdout } = await run('git', [...pre, ...args], {
      cwd,
      maxBuffer: 20 * 1024 * 1024,
      env: { PATH: process.env.PATH, HOME: process.env.HOME || '/tmp', GIT_TERMINAL_PROMPT: '0' },
    });
    return stdout;
  } catch (e) {
    // mai il token nei messaggi d'errore
    const message = String(e.stderr || e.message).replaceAll(config.githubToken, '***').trim();
    throw new GitError(message.split('\n').slice(-3).join(' '));
  }
}

const repoUrl = (repo) => `${config.githubGitBase}/${repo}.git`;

export async function clone(repo, branch, dir) {
  await git(process.cwd(), ['clone', '--branch', branch, '--single-branch', repoUrl(repo), dir], { auth: true });
}

/** Ultime modifiche dal repo (anche SiteHub fa commit, per il blog), prima di iniziare a lavorare. */
export async function pull(dir) {
  await git(dir, ['pull', '--rebase', '--autostash'], { auth: true });
}

/** File cambiati rispetto all'ultimo commit: [{status: 'M'|'A'|'D'|'?', path}] */
export async function changes(dir) {
  const out = await git(dir, ['status', '--porcelain', '--untracked-files=all']);
  return out.split('\n').filter(Boolean).map((line) => ({
    status: line.slice(0, 2).trim().replace('??', 'A') || 'M',
    path: line.slice(3).replace(/^"(.*)"$/, '$1'),
  }));
}

export async function diff(dir) {
  await git(dir, ['add', '--intent-to-add', '--all']);
  // rispetto all'ultimo commit: comprende anche le modifiche già nell'indice (es. un annullamento)
  return git(dir, ['diff', 'HEAD', '--stat', '--patch', '--no-color']);
}

export async function discardChanges(dir) {
  await git(dir, ['reset', '--hard', '--quiet']);
  await git(dir, ['clean', '-fd', '--quiet']);
}

/**
 * Commit e push. Su main di norma; su un altro ramo solo se richiesto.
 * Prima si recuperano le novità del repo (SiteHub può aver pubblicato un articolo nel frattempo).
 */
export async function publish(dir, { message, author, branch }) {
  await git(dir, ['add', '--all']);
  const authorArg = `${author.name} <${author.email}>`;
  await git(dir, ['-c', `user.name=${author.name}`, '-c', `user.email=${author.email}`, 'commit', '--quiet', '-m', message, `--author=${authorArg}`]);
  const current = (await git(dir, ['rev-parse', '--abbrev-ref', 'HEAD'])).trim();
  if (branch && branch !== current) {
    await git(dir, ['push', 'origin', `HEAD:refs/heads/${branch}`], { auth: true });
    const sha = (await git(dir, ['rev-parse', 'HEAD'])).trim();
    // la proposta sta solo sull'altro ramo: la sessione torna al ramo principale com'è online,
    // altrimenti la prossima pubblicazione la porterebbe anche lì
    await git(dir, ['reset', '--hard', '--quiet', '@{upstream}']);
    return sha;
  }
  await git(dir, ['-c', `user.name=${author.name}`, '-c', `user.email=${author.email}`, 'pull', '--rebase', '--quiet'], { auth: true });
  await git(dir, ['push', 'origin', 'HEAD'], { auth: true });
  return (await git(dir, ['rev-parse', 'HEAD'])).trim();
}

const mirrors = new Map(); // cartella della copia → ultima operazione (una alla volta per repo)

/**
 * Storico di un ramo, da una copia locale del repo (solo dati git, nessun file del sito) aggiornata a ogni
 * richiesta. Per ogni commit: sha, genitori, autore, data, titolo, sessione dell'editor (se pubblicato da qui), file.
 */
export function history(repo, branch, dir, limit = 30) {
  const job = (mirrors.get(dir) || Promise.resolve()).catch(() => {}).then(async () => {
    if (await fs.access(dir).then(() => true, () => false)) {
      await git(dir, ['fetch', '--quiet', '--prune', 'origin', '+refs/heads/*:refs/heads/*'], { auth: true });
    } else {
      await fs.mkdir(path.dirname(dir), { recursive: true });
      await git(process.cwd(), ['clone', '--bare', '--quiet', repoUrl(repo), dir], { auth: true });
    }
    const out = await git(dir, ['log', `-n${limit}`, '--name-only',
      '--format=%x1e%H%x1f%P%x1f%an%x1f%aI%x1f%s%x1f%(trailers:key=Editor-Session,valueonly,separator=%x2c)', branch, '--']);
    return out.split('\x1e').filter((r) => r.trim()).map((record) => {
      const [head, ...rest] = record.split('\n');
      const [sha, parents, author, at, subject, session] = head.split('\x1f');
      return {
        sha, parents: parents ? parents.split(' ') : [], author, at, subject,
        session: session?.trim() || null, files: rest.map((l) => l.trim()).filter(Boolean),
      };
    });
  });
  mirrors.set(dir, job);
  return job;
}

/**
 * Prepara l'annullamento di un commit nella cartella di lavoro (senza fare commit: si guarda e poi si pubblica).
 * Se nel frattempo sono cambiate le stesse righe non si può fare in automatico: si torna com'era.
 */
export async function revert(dir, sha) {
  try {
    await git(dir, ['merge-base', '--is-ancestor', sha, 'HEAD']);
  } catch {
    throw new GitError('Pubblicazione non trovata nella storia del sito');
  }
  const parents = (await git(dir, ['rev-list', '--parents', '-n1', sha])).trim().split(' ').length - 1;
  if (parents === 0) throw new GitError('È la prima versione del sito: non si può annullare');
  if (parents > 1) throw new GitError('È un\'unione di rami: va annullata a mano');
  try {
    await git(dir, ['-c', 'user.name=Editor', '-c', 'user.email=editor@localhost', 'revert', '--no-commit', sha]);
  } catch {
    await git(dir, ['revert', '--abort']).catch(() => {});
    await discardChanges(dir);
    throw new ConflictError('nel frattempo sono cambiate le stesse righe');
  }
}

/** Repo nuovo (privato) nell'organizzazione dei siti. */
export async function createRepo(name, description) {
  const res = await fetch(`${config.githubApiBase}/orgs/${config.githubOrg}/repos`, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${config.githubToken}`,
      Accept: 'application/vnd.github+json',
      'X-GitHub-Api-Version': '2022-11-28',
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ name, description, private: true, auto_init: false, has_wiki: false, has_projects: false }),
  });
  const body = await res.json().catch(() => ({}));
  if (res.status === 422) throw new GitError(`Esiste già un repo ${config.githubOrg}/${name}`);
  if (!res.ok) throw new GitError(`GitHub ha rifiutato la creazione del repo (HTTP ${res.status}): ${body.message || ''}`);
  return body.full_name;
}

/** Prima versione del sito: la cartella diventa un repo con un commit e va su main. */
export async function pushFirstCommit(dir, repo, { message, author }) {
  await git(dir, ['init', '--quiet', '--initial-branch=main']);
  await git(dir, ['add', '--all']);
  await git(dir, ['-c', `user.name=${author.name}`, '-c', `user.email=${author.email}`, 'commit', '--quiet', '-m', message]);
  await git(dir, ['remote', 'add', 'origin', repoUrl(repo)]);
  await git(dir, ['push', '--quiet', 'origin', 'main'], { auth: true });
}

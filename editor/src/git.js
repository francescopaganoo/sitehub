// Operazioni git e GitHub. Il token GitHub viaggia solo nell'intestazione di ogni comando
// (http.extraHeader): non finisce in .git/config né in altri file della cartella di lavoro,
// che Claude può leggere.

import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { config } from './config.js';

const run = promisify(execFile);

export class GitError extends Error {}

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
  return git(dir, ['diff', '--stat', '--patch', '--no-color']);
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
  } else {
    await git(dir, ['-c', `user.name=${author.name}`, '-c', `user.email=${author.email}`, 'pull', '--rebase', '--quiet'], { auth: true });
    await git(dir, ['push', 'origin', 'HEAD'], { auth: true });
  }
  return (await git(dir, ['rev-parse', 'HEAD'])).trim();
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

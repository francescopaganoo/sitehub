// Operazioni sui file del sito che fa il servizio (non Claude): versione di CSS e JS,
// sito nuovo dallo starter, anteprima.

import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
import { config } from './config.js';

async function walk(root, dir = '') {
  const out = [];
  for (const entry of await fs.readdir(path.join(root, dir), { withFileTypes: true })) {
    if (entry.name === '.git') continue;
    const rel = dir ? `${dir}/${entry.name}` : entry.name;
    if (entry.isDirectory()) out.push(...await walk(root, rel));
    else out.push(rel);
  }
  return out;
}

const sha8 = async (file) => crypto.createHash('sha1').update(await fs.readFile(file)).digest('hex').slice(0, 8);

/**
 * Come versione.sh: aggiorna ?v=… di style.css e script.js in tutte le pagine (template del blog compresi),
 * così i visitatori scaricano subito i file nuovi. Restituisce le pagine cambiate.
 */
export async function stampVersions(root) {
  const versions = {};
  for (const name of ['style.css', 'script.js']) {
    try {
      versions[name] = await sha8(path.join(root, name));
    } catch {
      // il sito non ha questo file
    }
  }
  const changed = [];
  for (const file of (await walk(root)).filter((f) => f.endsWith('.html'))) {
    const full = path.join(root, file);
    const before = await fs.readFile(full, 'utf8');
    let after = before;
    for (const [name, v] of Object.entries(versions)) {
      const re = new RegExp(`/${name.replace('.', '\\.')}(\\?v=[a-f0-9]+)?"`, 'g');
      after = after.replace(re, `/${name}?v=${v}"`);
    }
    if (after !== before) {
      await fs.writeFile(full, after);
      changed.push(file);
    }
  }
  return changed;
}

/**
 * Sito nuovo: copia dello starter con la riga dello script di SiteHub già inserita (se il sito è registrato).
 * Testi, dati e grafica li sostituisce poi Claude a partire dal brief.
 */
export async function prepareFromStarter(root, { sitehubSnippet }) {
  await fs.cp(config.starterDir, root, { recursive: true, filter: (src) => !src.split(path.sep).includes('.git') });
  if (!sitehubSnippet) return;
  for (const file of (await walk(root)).filter((f) => f.endsWith('.html'))) {
    const full = path.join(root, file);
    const content = await fs.readFile(full, 'utf8');
    await fs.writeFile(full, content.replace(/<!-- script SiteHub:[^>]*-->/g, sitehubSnippet));
  }
}

// ---------------------------------------------------------------- anteprima

const TYPES = {
  '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8', '.js': 'text/javascript; charset=utf-8',
  '.json': 'application/json', '.xml': 'application/xml', '.txt': 'text/plain; charset=utf-8', '.svg': 'image/svg+xml',
  '.webp': 'image/webp', '.png': 'image/png', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg', '.gif': 'image/gif',
  '.ico': 'image/x-icon', '.woff2': 'font/woff2', '.woff': 'font/woff', '.pdf': 'application/pdf',
};

/**
 * Il sito usa percorsi dalla radice (/style.css, /chi-siamo/): nell'anteprima vivono sotto /anteprima/{chiave}/,
 * quindi negli HTML e nei CSS serviti si aggiunge il prefisso. I file sul disco non cambiano.
 */
export function rewriteRoot(text, prefix) {
  return text
    .replace(/(\s(?:href|src|action|poster|data-ssg-src)=")\/(?!\/)/g, `$1${prefix}/`)
    .replace(/(\ssrcset=")([^"]*)"/g, (_, attr, list) => `${attr}${list.replace(/(^|,\s*)\/(?!\/)/g, `$1${prefix}/`)}"`)
    .replace(/url\((['"]?)\/(?!\/)/g, `url($1${prefix}/`);
}

/** File dell'anteprima per un percorso del sito: /x/ → x/index.html, mancante → 404.html. */
export async function previewFile(root, urlPath) {
  let rel = decodeURIComponent(urlPath).replace(/^\/+/, '');
  if (rel === '' || rel.endsWith('/')) rel += 'index.html';
  const resolved = path.resolve(root, rel);
  const relCheck = path.relative(root, resolved);
  const hidden = relCheck.split(path.sep).some((part) => part.startsWith('.') || part === '_sitehub');
  if (relCheck.startsWith('..') || hidden) return { status: 404, file: path.join(root, '404.html') };
  try {
    const stat = await fs.stat(resolved);
    if (stat.isDirectory()) return { redirect: `${urlPath.replace(/\/?$/, '/')}` };
    return { status: 200, file: resolved };
  } catch {
    return { status: 404, file: path.join(root, '404.html') };
  }
}

export const contentType = (file) => TYPES[path.extname(file).toLowerCase()] || 'application/octet-stream';

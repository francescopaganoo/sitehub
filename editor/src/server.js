// Servizio editor dei siti statici. Lo usa il gestionale (che controlla permessi e utenti);
// il browser vede direttamente solo le anteprime, con una chiave casuale per sessione.
//
//   GET    /health
//   GET    /api/sessions?repo=org/nome                sessioni (per sito)
//   POST   /api/sessions                              modifica di un sito: {repo, branch, siteName, domain, guideUrl, user}
//   POST   /api/sites                                 sito nuovo dallo starter: {repoName, description, siteName, domain, guideUrl, sitehubSnippet, user}
//   GET    /api/sessions/:id
//   POST   /api/sessions/:id/messages                 chat: {text, apiKey, model, effort, user} → eventi (text/event-stream)
//   POST   /api/sessions/:id/stop                     interrompe Claude
//   GET    /api/sessions/:id/changes                  file cambiati
//   GET    /api/sessions/:id/diff                     differenze (testo)
//   POST   /api/sessions/:id/publish                  {message, branch, user} → commit e push
//   POST   /api/sessions/:id/discard                  annulla le modifiche non pubblicate
//   DELETE /api/sessions/:id                          chiude la sessione
//   GET    /api/starter.zip                           starter da scaricare
//   GET    /anteprima/{chiave}/…                      anteprima del sito (pubblica, chiave casuale)

import http from 'node:http';
import crypto from 'node:crypto';
import fs from 'node:fs/promises';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { config, workDir } from './config.js';
import * as sessions from './sessions.js';
import { previewFile, rewriteRoot, contentType } from './site.js';

const exec = promisify(execFile);

const json = (res, status, body) => {
  res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8' });
  res.end(JSON.stringify(body));
};

const authorized = (req) => {
  const given = Buffer.from(String(req.headers.authorization || '').replace(/^Bearer\s+/i, ''));
  const expected = Buffer.from(config.token);
  return given.length === expected.length && crypto.timingSafeEqual(given, expected);
};

async function readJson(req) {
  let size = 0;
  const chunks = [];
  for await (const chunk of req) {
    size += chunk.length;
    if (size > 2 * 1024 * 1024) throw new sessions.SessionError(413, 'Richiesta troppo grande');
    chunks.push(chunk);
  }
  if (!chunks.length) return {};
  try {
    return JSON.parse(Buffer.concat(chunks).toString('utf8'));
  } catch {
    throw new sessions.SessionError(400, 'JSON non valido');
  }
}

async function preview(req, res, key, rest) {
  const meta = await sessions.byPreviewKey(key);
  if (!meta) return json(res, 404, { message: 'Anteprima non trovata' });
  const root = workDir(meta.id);
  const found = await previewFile(root, rest || '/');
  if (found.redirect) {
    res.writeHead(301, { Location: `/anteprima/${key}${found.redirect}` });
    return res.end();
  }
  let body;
  try {
    body = await fs.readFile(found.file);
  } catch {
    res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
    return res.end('Pagina non trovata');
  }
  const type = contentType(found.file);
  if (type.startsWith('text/html') || type.startsWith('text/css')) {
    body = rewriteRoot(body.toString('utf8'), `/anteprima/${key}`);
  }
  res.writeHead(found.status, {
    'Content-Type': type,
    'Cache-Control': 'no-store',
    'X-Robots-Tag': 'noindex, nofollow',
    'Referrer-Policy': 'no-referrer',
  });
  res.end(body);
}

async function chat(req, res, id) {
  const body = await readJson(req);
  res.writeHead(200, { 'Content-Type': 'text/event-stream; charset=utf-8', 'Cache-Control': 'no-cache', 'X-Accel-Buffering': 'no' });
  const send = (event) => res.write(`data: ${JSON.stringify(event)}\n\n`);
  const keepAlive = setInterval(() => res.write(': ping\n\n'), 15000);
  try {
    await sessions.message(id, body, send);
  } catch (e) {
    send({ type: 'error', message: e.message, status: e.status });
  } finally {
    clearInterval(keepAlive);
    res.end();
  }
}

async function starterZip(res) {
  const { stdout } = await exec('zip', ['-r', '-q', '-', '.', '-x', '.git/*'], { cwd: config.starterDir, encoding: 'buffer', maxBuffer: 50 * 1024 * 1024 });
  res.writeHead(200, { 'Content-Type': 'application/zip', 'Content-Disposition': 'attachment; filename="sito-starter.zip"' });
  res.end(stdout);
}

async function route(req, res) {
  const url = new URL(req.url, 'http://editor');
  const p = url.pathname;
  const m = (re) => p.match(re);

  if (p === '/health') return json(res, 200, { ok: true });
  let match;
  if ((match = m(/^\/anteprima\/([a-f0-9]{32})(\/.*)?$/)) && req.method === 'GET') return preview(req, res, match[1], match[2]);
  if (!p.startsWith('/api/')) return json(res, 404, { message: 'Non trovato' });
  if (!authorized(req)) return json(res, 401, { message: 'Non autorizzato' });

  if (p === '/api/sessions' && req.method === 'GET') return json(res, 200, { sessions: (await sessions.list(url.searchParams.get('repo'))).map(sessions.view) });
  if (p === '/api/sessions' && req.method === 'POST') return json(res, 201, { session: sessions.view(await sessions.create(await readJson(req))) });
  if (p === '/api/sites' && req.method === 'POST') return json(res, 201, { session: sessions.view(await sessions.createSite(await readJson(req))) });
  if (p === '/api/starter.zip' && req.method === 'GET') return starterZip(res);

  if ((match = m(/^\/api\/sessions\/([^/]+)(\/[a-z]+)?$/))) {
    const [, id, action = ''] = match;
    const key = `${req.method} ${action}`;
    switch (key) {
      case 'GET ': return json(res, 200, { session: sessions.view(await sessions.get(id)) });
      case 'POST /messages': return chat(req, res, id);
      case 'POST /stop': return json(res, 200, { stopped: sessions.stop(id) });
      case 'GET /changes': return json(res, 200, { files: await sessions.changes(id) });
      case 'GET /diff': return json(res, 200, { diff: await sessions.diff(id) });
      case 'POST /publish': return json(res, 200, await sessions.publish(id, await readJson(req)));
      case 'POST /discard': await sessions.discard(id); return json(res, 200, { files: [] });
      case 'DELETE ': await sessions.remove(id); return json(res, 200, { deleted: id });
    }
  }
  return json(res, 404, { message: 'Non trovato' });
}

const server = http.createServer((req, res) => {
  route(req, res).catch((e) => {
    if (!(e instanceof sessions.SessionError)) console.error(e);
    if (res.headersSent) return res.end();
    json(res, e.status || 500, { message: e instanceof sessions.SessionError ? e.message : 'Errore interno dell\'editor' });
  });
});
server.requestTimeout = 0; // le risposte della chat possono durare minuti
server.listen(config.port, () => console.log(`editor in ascolto sulla porta ${config.port}`));

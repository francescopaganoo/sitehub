// Servizio editor con un finto agente (EDITOR_FAKE_AGENT=1) e un finto GitHub (repo git locali):
// tutto il giro tranne Claude. Lancio: npm test

import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import http from 'node:http';

const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'editor-test-'));
const remotes = path.join(tmp, 'remotes');
const starter = path.resolve(import.meta.dirname, '../../starter');
const TOKEN = 'token-di-prova';
const GITHUB_TOKEN = 'ghp_segretissimo';
let base, editor, fakeGithub;

const sh = (cwd, ...args) => execFileSync('git', args, { cwd, encoding: 'utf8' }).trim();

function bareRepo(name, from) {
  const bare = path.join(remotes, 'Plan-Studios', `${name}.git`);
  fs.mkdirSync(bare, { recursive: true });
  sh(bare, 'init', '--bare', '--quiet', '--initial-branch=main');
  if (from) {
    const work = path.join(tmp, `seed-${name}`);
    fs.cpSync(from, work, { recursive: true, filter: (s) => !s.includes('/.git') });
    sh(work, 'init', '--quiet', '--initial-branch=main');
    sh(work, 'add', '-A');
    sh(work, '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-q', '-m', 'primo');
    sh(work, 'push', '-q', bare, 'main');
  }
  return bare;
}

const api = async (method, p, body) => {
  const res = await fetch(base + p, { method, headers: { Authorization: `Bearer ${TOKEN}`, 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined });
  const type = res.headers.get('content-type') || '';
  return { status: res.status, body: type.includes('json') ? await res.json() : await res.text() };
};

async function chat(id, text) {
  const res = await fetch(`${base}/api/sessions/${id}/messages`, { method: 'POST', headers: { Authorization: `Bearer ${TOKEN}` }, body: JSON.stringify({ text, user: { name: 'Mario Rossi', email: 'mario@planstudios.it' } }) });
  const raw = await res.text();
  return raw.split('\n\n').filter((l) => l.startsWith('data: ')).map((l) => JSON.parse(l.slice(6)));
}

before(async () => {
  // finto GitHub: POST /orgs/:org/repos crea un repo vuoto locale
  fakeGithub = http.createServer((req, res) => {
    let data = '';
    req.on('data', (c) => { data += c; });
    req.on('end', () => {
      const m = req.url.match(/^\/orgs\/([^/]+)\/repos$/);
      if (req.method !== 'POST' || !m) { res.writeHead(404); return res.end('{}'); }
      if (req.headers.authorization !== `Bearer ${GITHUB_TOKEN}`) { res.writeHead(401); return res.end('{}'); }
      const { name } = JSON.parse(data);
      if (fs.existsSync(path.join(remotes, m[1], `${name}.git`))) { res.writeHead(422); return res.end('{"message":"exists"}'); }
      bareRepo(name);
      res.writeHead(201, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ full_name: `${m[1]}/${name}` }));
    });
  }).listen(0);
  await new Promise((r) => fakeGithub.once('listening', r));

  bareRepo('sito-prova', starter);
  const port = 20000 + Math.floor(Math.random() * 20000);
  base = `http://localhost:${port}`;
  editor = spawn(process.execPath, ['src/server.js'], {
    cwd: path.resolve(import.meta.dirname, '..'),
    env: {
      ...process.env, PORT: String(port), EDITOR_TOKEN: TOKEN, GITHUB_TOKEN, EDITOR_FAKE_AGENT: '1',
      DATA_DIR: path.join(tmp, 'data'), STARTER_DIR: starter,
      GITHUB_GIT_BASE: `file://${remotes}`, GITHUB_API_BASE: `http://localhost:${fakeGithub.address().port}`,
    },
    stdio: ['ignore', 'pipe', 'inherit'],
  });
  await new Promise((r) => editor.stdout.once('data', r));
});

after(() => {
  editor?.kill();
  fakeGithub?.close();
  fs.rmSync(tmp, { recursive: true, force: true });
});

test('senza chiave condivisa: 401, ma la salute risponde', async () => {
  assert.equal((await fetch(`${base}/api/sessions`)).status, 401);
  assert.equal((await fetch(`${base}/api/sessions`, { headers: { Authorization: 'Bearer sbagliato' } })).status, 401);
  assert.equal((await fetch(`${base}/health`)).status, 200);
});

let session;

test('modifica di un sito: repo scaricato, token mai nei file', async () => {
  const res = await api('POST', '/api/sessions', { repo: 'Plan-Studios/sito-prova', siteName: 'Prova', domain: 'https://prova.it', user: { name: 'Mario Rossi' } });
  assert.equal(res.status, 201, JSON.stringify(res.body));
  session = res.body.session;
  assert.match(session.previewPath, /^\/anteprima\/[a-f0-9]{32}\/$/);
  assert.equal(session.guide, undefined, 'la guida non va restituita');
  const work = path.join(tmp, 'data', 'work', session.id);
  assert.ok(fs.existsSync(path.join(work, 'index.html')));
  // grep esce con 1 se non trova niente: è il risultato atteso
  let found = '';
  try {
    found = execFileSync('grep', ['-rl', GITHUB_TOKEN, work, path.join(tmp, 'data', 'sessions')], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] });
  } catch {
    found = '';
  }
  assert.equal(found, '', 'token GitHub salvato nei file della sessione');
});

test('chat: modifiche, file protetti rifiutati, costo e file cambiati alla fine', async () => {
  const events = await chat(session.id, 'sostituisci «+39 000 000 0000» con «+39 333 123 4567» e modifica blog');
  const edits = events.filter((e) => e.type === 'tool').map((e) => e.target);
  assert.ok(edits.includes('index.html'), JSON.stringify(events));
  const denied = events.find((e) => e.type === 'denied');
  assert.match(denied.message, /generato da SiteHub/);
  const done = events.at(-1);
  assert.equal(done.type, 'done');
  assert.ok(done.files.some((f) => f.path === 'index.html' && f.status === 'M'));
  const again = await api('GET', `/api/sessions/${session.id}`);
  assert.equal(again.body.session.history.length, 2);
  assert.match(again.body.session.history[1].text, /Fatto/);
});

test('anteprima: indirizzi del sito riscritti, cartelle interne nascoste, chiave sbagliata 404', async () => {
  const key = session.previewPath.match(/anteprima\/([a-f0-9]{32})/)[1];
  const home = await fetch(`${base}/anteprima/${key}/`);
  assert.equal(home.status, 200);
  const html = await home.text();
  assert.match(html, /\+39 333 123 4567/);
  assert.match(html, new RegExp(`href="/anteprima/${key}/style\\.css`));
  assert.match(html, new RegExp(`src="/anteprima/${key}/assets/img/logo\\.svg"`));
  assert.ok(!/href="\/style\.css/.test(html));
  assert.equal((await fetch(`${base}/anteprima/${key}/privacy-policy`, { redirect: 'manual' })).status, 301);
  assert.equal((await fetch(`${base}/anteprima/${key}/privacy-policy/`)).status, 200);
  const missing = await fetch(`${base}/anteprima/${key}/non-esiste/`);
  assert.equal(missing.status, 404);
  assert.match(await missing.text(), /Pagina non trovata/);
  assert.equal((await fetch(`${base}/anteprima/${key}/_sitehub/templates/blog.html`)).status, 404);
  assert.equal((await fetch(`${base}/anteprima/${key}/.git/config`)).status, 404);
  assert.equal((await fetch(`${base}/anteprima/${'0'.repeat(32)}/`)).status, 404);
});

test('differenze, poi pubblicazione su main con versione di CSS aggiornata', async () => {
  const d = await api('GET', `/api/sessions/${session.id}/diff`);
  assert.match(d.body.diff, /\+39 333 123 4567/);
  fs.appendFileSync(path.join(tmp, 'data', 'work', session.id, 'style.css'), '\n/* modifica */\n');
  // il sito di prova è lo starter: segnaposto ovunque, si pubblica solo confermando
  const check = await api('GET', `/api/sessions/${session.id}/check`);
  assert.ok(check.body.placeholders.some((p) => p.what === 'dominio del sito' && p.file === 'index.html' && p.line > 0));
  assert.ok(check.body.placeholders.every((p) => !p.file.startsWith('assets/')));
  const refused = await api('POST', `/api/sessions/${session.id}/publish`, { message: 'x', user: { name: 'Mario Rossi' } });
  assert.equal(refused.status, 409);
  assert.equal(refused.body.code, 'placeholders');
  assert.match(refused.body.message, /segnaposto/);
  const pub = await api('POST', `/api/sessions/${session.id}/publish`, { message: 'Nuovo numero di telefono', ignorePlaceholders: true, user: { name: 'Mario Rossi', email: 'mario@planstudios.it' } });
  assert.equal(pub.status, 200, JSON.stringify(pub.body));
  const bare = path.join(remotes, 'Plan-Studios', 'sito-prova.git');
  assert.equal(sh(bare, 'log', '-1', '--format=%s|%an', 'main'), 'Nuovo numero di telefono|Mario Rossi');
  const index = sh(bare, 'show', 'main:index.html');
  assert.match(index, /\+39 333 123 4567/);
  assert.match(index, /style\.css\?v=[a-f0-9]{8}"/);
  assert.equal((await api('POST', `/api/sessions/${session.id}/publish`, {})).status, 409, 'nulla da pubblicare');
});

test('pubblicazione su un altro ramo, solo se richiesto', async () => {
  await chat(session.id, 'sostituisci «Servizio 1» con «Antennistica»');
  const pub = await api('POST', `/api/sessions/${session.id}/publish`, { branch: 'prova-servizi', ignorePlaceholders: true, user: { name: 'Mario Rossi' } });
  assert.equal(pub.body.branch, 'prova-servizi');
  const bare = path.join(remotes, 'Plan-Studios', 'sito-prova.git');
  assert.match(sh(bare, 'show', 'prova-servizi:index.html'), /Antennistica/);
  assert.doesNotMatch(sh(bare, 'show', 'main:index.html'), /Antennistica/);
  // la sessione torna a main: la proposta non finisce online con la prossima pubblicazione
  assert.deepEqual((await api('GET', `/api/sessions/${session.id}/changes`)).body.files, []);
  assert.doesNotMatch(fs.readFileSync(path.join(tmp, 'data', 'work', session.id, 'index.html'), 'utf8'), /Antennistica/);
});

test('caricamento di immagini: solo in assets/, nome ripulito, mai sopra un file esistente', async () => {
  const put = (p, data) => fetch(`${base}/api/sessions/${session.id}/files?path=${encodeURIComponent(p)}`, { method: 'PUT', headers: { Authorization: `Bearer ${TOKEN}` }, body: data }).then(async (r) => ({ status: r.status, body: await r.json() }));
  const png = Buffer.from('89504e470d0a1a0a', 'hex');
  let r = await put('assets/img/Logo Officina.PNG', png);
  assert.equal(r.status, 201);
  assert.equal(r.body.path, 'assets/img/logo-officina.png');
  r = await put('assets/img/logo-officina.png', png);
  assert.equal(r.body.path, 'assets/img/logo-officina-2.png');
  for (const bad of ['index.html', 'assets/img/x.js', '../fuori.png', 'assets/blog/2026/x.png', 'style.css']) {
    assert.equal((await put(bad, png)).status, 422, bad);
  }
  await api('POST', `/api/sessions/${session.id}/discard`);
});

test('annulla le modifiche non pubblicate', async () => {
  await chat(session.id, 'sostituisci «Chi siamo» con «La nostra storia»');
  assert.ok((await api('GET', `/api/sessions/${session.id}/changes`)).body.files.length > 0);
  await api('POST', `/api/sessions/${session.id}/discard`);
  assert.deepEqual((await api('GET', `/api/sessions/${session.id}/changes`)).body.files, []);
});

const bareProva = () => path.join(remotes, 'Plan-Studios', 'sito-prova.git');
const historyOf = async () => (await api('GET', '/api/history?repo=Plan-Studios/sito-prova')).body.commits;
const publishAs = (message) => api('POST', `/api/sessions/${session.id}/publish`, { message, ignorePlaceholders: true, user: { name: 'Mario Rossi', email: 'mario@planstudios.it' } });

test('storico: pubblicazioni dall\'editor riconosciute, articoli del blog a parte', async () => {
  // un articolo pubblicato da SiteHub direttamente sul repo
  const other = path.join(tmp, 'clone-sitehub');
  sh(tmp, 'clone', '-q', bareProva(), other);
  fs.mkdirSync(path.join(other, 'blog', 'primo'), { recursive: true });
  fs.writeFileSync(path.join(other, 'blog', 'primo', 'index.html'), '<h1>Primo</h1>');
  sh(other, 'add', '-A');
  sh(other, '-c', 'user.name=SiteHub', '-c', 'user.email=s@h', 'commit', '-qm', 'Blog: primo');
  sh(other, 'push', '-q', 'origin', 'main');

  const [blog, tel, first] = await historyOf();
  assert.equal(blog.source, 'blog');
  assert.equal(blog.revertable, false);
  assert.equal(tel.subject, 'Nuovo numero di telefono');
  assert.equal(tel.source, 'editor');
  assert.equal(tel.session, session.id);
  assert.equal(tel.author, 'Mario Rossi');
  assert.ok(tel.revertable);
  assert.ok(tel.files.includes('index.html'));
  assert.equal(first.source, 'altro');
  assert.equal(first.revertable, false, 'la prima versione non si annulla');
  assert.equal((await api('GET', '/api/history?repo=../fuori')).status, 422);
});

test('annulla una pubblicazione: si prepara nella sessione, va online solo pubblicando', async () => {
  const tel = (await historyOf()).find((c) => c.subject === 'Nuovo numero di telefono');
  const r = await api('POST', `/api/sessions/${session.id}/revert`, { sha: tel.sha, subject: tel.subject, user: { name: 'Mario Rossi' } });
  assert.equal(r.status, 200, JSON.stringify(r.body));
  assert.ok(r.body.files.some((f) => f.path === 'index.html'));
  assert.match((await api('GET', `/api/sessions/${session.id}/diff`)).body.diff, /^-.*\+39 333 123 4567/m);
  assert.match(sh(bareProva(), 'show', 'main:index.html'), /\+39 333 123 4567/, 'online non cambia prima di pubblicare');
  const s = (await api('GET', `/api/sessions/${session.id}`)).body.session;
  assert.equal(s.revertOf.sha, tel.sha);
  assert.match(s.history.at(-1).text, /annullamento/);
  assert.equal((await api('POST', `/api/sessions/${session.id}/revert`, { sha: tel.sha })).status, 409, 'con modifiche in corso');

  assert.equal((await api('POST', `/api/sessions/${session.id}/publish`, { ignorePlaceholders: true, user: { name: 'Mario Rossi', email: 'mario@planstudios.it' } })).status, 200);
  assert.equal(sh(bareProva(), 'log', '-1', '--format=%s', 'main'), 'Annulla «Nuovo numero di telefono»');
  const index = sh(bareProva(), 'show', 'main:index.html');
  assert.match(index, /\+39 000 000 0000/);
  assert.doesNotMatch(index, /Antennistica/);
  assert.equal(sh(bareProva(), 'show', 'main:blog/primo/index.html'), '<h1>Primo</h1>', 'l\'articolo pubblicato dopo resta');
  assert.equal((await api('GET', `/api/sessions/${session.id}`)).body.session.revertOf, undefined);
});

test('annullamento non possibile: stesse righe cambiate dopo, articoli del blog, sha non valido', async () => {
  await chat(session.id, 'sostituisci «Chi siamo» con «La nostra storia»');
  await publishAs('Titolo A');
  await chat(session.id, 'sostituisci «La nostra storia» con «Storia»');
  await publishAs('Titolo B');
  const commits = await historyOf();
  const r = await api('POST', `/api/sessions/${session.id}/revert`, { sha: commits.find((c) => c.subject === 'Titolo A').sha });
  assert.equal(r.status, 409);
  assert.match(r.body.message, /Chiedi a Claude/);
  assert.deepEqual((await api('GET', `/api/sessions/${session.id}/changes`)).body.files, [], 'la cartella torna com\'era');
  const blog = await api('POST', `/api/sessions/${session.id}/revert`, { sha: commits.find((c) => c.source === 'blog').sha });
  assert.equal(blog.status, 422);
  assert.match(blog.body.message, /blog/);
  assert.equal((await api('POST', `/api/sessions/${session.id}/revert`, { sha: 'nonvalido' })).status, 422);
  assert.equal((await api('POST', `/api/sessions/${session.id}/revert`, { sha: '0'.repeat(40) })).status, 422);
});

test('sito nuovo: repo creato, starter come primo commit con lo script di SiteHub', async () => {
  const snippet = '<script src="https://hub.planstudios.it/e/v1/pk_abc123.js" defer></script>';
  const res = await api('POST', '/api/sites', { repoName: 'officina-rossi', siteName: 'Officina Rossi', domain: 'https://officinarossi.it', sitehubSnippet: snippet, user: { name: 'Mario Rossi', email: 'mario@planstudios.it' } });
  assert.equal(res.status, 201, JSON.stringify(res.body));
  assert.equal(res.body.session.repo, 'Plan-Studios/officina-rossi');
  const bare = path.join(remotes, 'Plan-Studios', 'officina-rossi.git');
  assert.equal(sh(bare, 'log', '--format=%s', 'main'), 'Sito Officina Rossi creato dallo starter');
  const index = sh(bare, 'show', 'main:index.html');
  assert.ok(index.includes(snippet));
  assert.doesNotMatch(index, /script SiteHub:/);
  assert.match(index, /style\.css\?v=[a-f0-9]{8}"/);
  assert.equal((await api('POST', '/api/sites', { repoName: 'officina-rossi', siteName: 'x' })).status, 422, 'repo già esistente');
  assert.equal((await api('POST', '/api/sites', { repoName: 'Nome Non Valido', siteName: 'x' })).status, 422);
});

test('abbonamento: modalità pubblicizzata, token mai salvato nella sessione', async () => {
  const info = await api('GET', '/api/info');
  assert.ok(info.body.authModes.includes('subscription'));
  const token = 'sk-ant-oat01-test-subscription';
  const response = await fetch(`${base}/api/sessions/${session.id}/messages`, {
    method: 'POST', headers: { Authorization: `Bearer ${TOKEN}` },
    body: JSON.stringify({ text: 'Controlla il sito', authMode: 'subscription', oauthToken: token }),
  });
  const stream = await response.text();
  assert.equal(response.status, 200);
  assert.ok(stream.includes('"ok":true'));
  assert.ok(!stream.includes(token));
  const stored = fs.readFileSync(path.join(tmp, 'data', 'sessions', `${session.id}.json`), 'utf8');
  assert.ok(!stored.includes(token));
  const invalid = await api('POST', `/api/sessions/${session.id}/messages`, { text: 'x', authMode: 'other' });
  assert.equal(invalid.status, 200);
  assert.match(invalid.body, /Modalità Claude non valida/);
});

test('starter da scaricare', async () => {
  const res = await fetch(`${base}/api/starter.zip`, { headers: { Authorization: `Bearer ${TOKEN}` } });
  assert.equal(res.status, 200);
  const zip = Buffer.from(await res.arrayBuffer());
  assert.equal(zip.subarray(0, 2).toString(), 'PK');
});

test('chiusura della sessione: cartella eliminata', async () => {
  assert.equal((await api('DELETE', `/api/sessions/${session.id}`)).status, 200);
  assert.ok(!fs.existsSync(path.join(tmp, 'data', 'work', session.id)));
  assert.equal((await api('GET', `/api/sessions/${session.id}`)).status, 404);
});

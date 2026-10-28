// Regole di permesso di Claude sul sito: dentro la cartella sì, fuori e file protetti no.

import { test } from 'node:test';
import assert from 'node:assert/strict';

process.env.EDITOR_TOKEN ||= 'x';
process.env.GITHUB_TOKEN ||= 'x';
const { permission, insideSite } = await import('../src/agent.js');

const root = '/data/work/abc';
const allow = (tool, input) => assert.equal(permission(root, tool, input).behavior, 'allow', `${tool} ${JSON.stringify(input)}`);
const deny = (tool, input) => assert.equal(permission(root, tool, input).behavior, 'deny', `${tool} ${JSON.stringify(input)}`);

test('dentro il sito: lettura, ricerca e modifica', () => {
  allow('Read', { file_path: '/data/work/abc/index.html' });
  allow('Read', { file_path: 'chi-siamo/index.html' });
  allow('Edit', { file_path: '/data/work/abc/style.css', old_string: 'a', new_string: 'b' });
  allow('Write', { file_path: '/data/work/abc/servizi/nuova/index.html', content: '' });
  allow('Write', { file_path: '/data/work/abc/_sitehub/templates/articolo.html', content: '' });
  allow('Glob', { pattern: '**/*.html' });
  allow('Grep', { pattern: 'telefono', path: '/data/work/abc' });
});

test('fuori dal sito: mai, nemmeno con ../ o percorsi assoluti', () => {
  deny('Read', { file_path: '/etc/passwd' });
  deny('Read', { file_path: '/data/work/abc/../xyz/index.html' });
  deny('Read', { file_path: '../../sessions/abc.json' });
  deny('Read', { file_path: '/proc/self/environ' });
  deny('Glob', { pattern: '*', path: '/data' });
  deny('Grep', { pattern: 'token', path: '/data/work' });
  deny('Write', { file_path: '/data/work/abcdef/index.html', content: '' });
});

test('.git e file generati da SiteHub', () => {
  deny('Read', { file_path: '/data/work/abc/.git/config' });
  deny('Edit', { file_path: '/data/work/abc/.git/HEAD', old_string: 'a', new_string: 'b' });
  deny('Write', { file_path: '/data/work/abc/blog/index.html', content: '' });
  deny('Edit', { file_path: '/data/work/abc/sitemap-blog.xml', old_string: 'a', new_string: 'b' });
  deny('Write', { file_path: '/data/work/abc/_sitehub/content/posts/x.json', content: '' });
  deny('Write', { file_path: '/data/work/abc/assets/blog/2026/10/x.webp', content: '' });
  allow('Read', { file_path: '/data/work/abc/blog/index.html' }); // leggerli sì (per capire com'è il blog)
});

test('terminale e agenti bloccati, consultazione web consentita', () => {
  deny('Bash', { command: 'ls' });
  allow('WebFetch', { url: 'https://example.com' });
  allow('WebSearch', { query: 'UX sito di riferimento' });
  deny('Agent', { prompt: 'x' });
});

test('percorso relativo del sito', () => {
  assert.equal(insideSite(root, '/data/work/abc/a/b.html'), 'a/b.html');
  assert.equal(insideSite(root, '/data/work/abcd/a.html'), null);
  assert.equal(insideSite(root, '.'), '');
});

test('errori del SDK spiegati', async () => {
  const { explain } = await import('../src/agent.js');
  assert.match(explain('Claude Code returned an error result: Invalid API key · Fix external API key'), /Credenziale Claude non valida o scaduta/);
  assert.match(explain('Your credit balance is too low'), /Credito Anthropic esaurito/);
  assert.match(explain('error_max_budget_usd'), /tetto di spesa/);
});


test('strumenti web e istruzioni coerenti', async () => {
  const { TOOLS, rules } = await import('../src/agent.js');
  assert.ok(TOOLS.includes('WebFetch'));
  assert.ok(TOOLS.includes('WebSearch'));
  const prompt = rules({ siteName: 'Test', domain: 'https://example.com' });
  assert.ok(!prompt.includes('niente internet'));
  assert.match(prompt, /aprilo con WebFetch/);
  assert.match(prompt, /non è un browser visuale/);
});

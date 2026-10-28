import { test } from 'node:test';
import assert from 'node:assert/strict';
process.env.EDITOR_TOKEN ||= 'test-editor';
process.env.GITHUB_TOKEN ||= 'test-github';
const { authEnvironment, resultError, redact, explain, withContext } = await import('../src/agent.js');

test('le modalità non ereditano né mescolano le credenziali', () => {
  const before = { api: process.env.ANTHROPIC_API_KEY, oauth: process.env.CLAUDE_CODE_OAUTH_TOKEN };
  process.env.ANTHROPIC_API_KEY = 'server-api';
  process.env.CLAUDE_CODE_OAUTH_TOKEN = 'server-oauth';
  try {
    const subscription = authEnvironment({ authMode: 'subscription', apiKey: 'ignored', oauthToken: 'selected-oauth' });
    assert.equal(subscription.CLAUDE_CODE_OAUTH_TOKEN, 'selected-oauth');
    assert.ok(!('ANTHROPIC_API_KEY' in subscription));
    const api = authEnvironment({ apiKey: 'selected-api', oauthToken: 'ignored' });
    assert.equal(api.ANTHROPIC_API_KEY, 'selected-api');
    assert.ok(!('CLAUDE_CODE_OAUTH_TOKEN' in api));
    assert.ok(!('GITHUB_TOKEN' in api));
    assert.match(api.CLAUDE_CONFIG_DIR, /claude$/, 'cronologia di Claude nel volume dei dati, non in HOME');
    assert.throws(() => authEnvironment({ authMode: 'invalid' }));
  } finally {
    if (before.api === undefined) delete process.env.ANTHROPIC_API_KEY;
    else process.env.ANTHROPIC_API_KEY = before.api;
    if (before.oauth === undefined) delete process.env.CLAUDE_CODE_OAUTH_TOKEN;
    else process.env.CLAUDE_CODE_OAUTH_TOKEN = before.oauth;
  }
});


test('errori di esecuzione: causa strutturata, fallback e credenziali nascoste', () => {
  assert.equal(resultError({ subtype: 'error_during_execution', errors: ['Actual SDK failure'] }), 'Actual SDK failure');
  assert.equal(resultError({ subtype: 'error_during_execution', errors: [] }, 'stderr detail'), 'stderr detail');
  assert.match(resultError({ subtype: 'error_during_execution' }), /non ha fornito dettagli/);
  assert.equal(resultError({ subtype: 'error_max_turns', errors: ['x'] }), 'error_max_turns');
  assert.equal(resultError({ subtype: 'success', result: 'API failed' }), 'API failed');
  assert.ok(!redact('bad custom-secret sk-ant-oat01-hidden', ['custom-secret']).includes('custom-secret'));
  assert.ok(!redact('bad sk-ant-oat01-hidden').includes('sk-ant-'));
  assert.match(explain('No conversation found with session ID abc'), /nuova sessione/);
  assert.match(explain('Model not found'), /modello/);
  assert.match(explain('EACCES writing config'), /permessi/);
});

test('conversazione non più riprendibile: si riparte con la chat precedente come contesto', () => {
  const history = [
    { role: 'user', text: 'Cambia il telefono nel footer' },
    { role: 'assistant', text: 'Fatto: ora è +39 333 123 4567.' },
    { role: 'assistant', text: '' },
  ];
  const prompt = withContext(history, 'Mettilo anche nella pagina contatti');
  assert.match(prompt, /Persona: Cambia il telefono nel footer/);
  assert.match(prompt, /Claude: Fatto: ora è \+39 333 123 4567\./);
  assert.match(prompt, /=== NUOVA RICHIESTA ===\nMettilo anche nella pagina contatti$/);
  assert.equal(withContext([], 'Ciao'), 'Ciao');
  assert.match(explain('No conversation found with session ID abc'), /non è più disponibile/);
});

import { test } from 'node:test';
import assert from 'node:assert/strict';
process.env.EDITOR_TOKEN ||= 'test-editor';
process.env.GITHUB_TOKEN ||= 'test-github';
const { authEnvironment } = await import('../src/agent.js');

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
    assert.throws(() => authEnvironment({ authMode: 'invalid' }));
  } finally {
    if (before.api === undefined) delete process.env.ANTHROPIC_API_KEY;
    else process.env.ANTHROPIC_API_KEY = before.api;
    if (before.oauth === undefined) delete process.env.CLAUDE_CODE_OAUTH_TOKEN;
    else process.env.CLAUDE_CODE_OAUTH_TOKEN = before.oauth;
  }
});

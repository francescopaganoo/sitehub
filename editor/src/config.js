// Configurazione del servizio editor (variabili d'ambiente su Coolify).
//
//   EDITOR_TOKEN        chiave condivisa con il gestionale: tutte le chiamate API la devono presentare
//   GITHUB_TOKEN        token dedicato all'editor: scrive sui repo dei siti e crea i repo nuovi (Administration)
//   GITHUB_ORG          organizzazione dei repo dei siti (es. Plan-Studios)
//   DATA_DIR            cartella persistente: cartelle di lavoro e sessioni (volume su Coolify)
//   EDITOR_MAX_BUDGET_USD  tetto di spesa per ogni messaggio (stima del SDK), predefinito 5
//
// La chiave Anthropic e il modello NON stanno qui: li manda il gestionale a ogni messaggio
// (impostazioni AI del gestionale), così si gestiscono in un solo posto.

import path from 'node:path';

const required = (name) => {
  const value = process.env[name];
  if (!value) throw new Error(`Variabile d'ambiente mancante: ${name}`);
  return value;
};

export const config = {
  port: Number(process.env.PORT || 80),
  token: required('EDITOR_TOKEN'),
  githubToken: required('GITHUB_TOKEN'),
  githubOrg: process.env.GITHUB_ORG || 'Plan-Studios',
  // per i test: repo e API di un finto GitHub
  githubGitBase: (process.env.GITHUB_GIT_BASE || 'https://github.com').replace(/\/+$/, ''),
  githubApiBase: (process.env.GITHUB_API_BASE || 'https://api.github.com').replace(/\/+$/, ''),
  dataDir: process.env.DATA_DIR || '/data',
  starterDir: process.env.STARTER_DIR || path.resolve(import.meta.dirname, '../../starter'),
  maxBudgetUsd: Number(process.env.EDITOR_MAX_BUDGET_USD || 5),
  // per i test: un finto agente al posto di Claude (nessuna chiamata, nessun costo)
  fakeAgent: process.env.EDITOR_FAKE_AGENT === '1',
};

export const workDir = (id) => path.join(config.dataDir, 'work', id);
export const metaFile = (id) => path.join(config.dataDir, 'sessions', `${id}.json`);
// cartella di Claude Code (cronologia delle conversazioni da riprendere): nel volume, non in HOME,
// altrimenti a ogni deploy le conversazioni non si possono più riprendere
export const claudeDir = () => path.join(config.dataDir, 'claude');

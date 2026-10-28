# Editor dei siti statici (Claude Agent SDK) – Coolify: build pack "Dockerfile",
# Dockerfile "editor/Dockerfile", contesto di build: la radice del repo (serve anche starter/). Porta 80.
#
# Variabili d'ambiente: EDITOR_TOKEN, GITHUB_TOKEN, GITHUB_ORG, PUBLIC_URL (vedi src/config.js)
# Volume persistente: /data (cartelle di lavoro e sessioni)

FROM node:24-slim

RUN apt-get update \
 && apt-get install -y --no-install-recommends git zip ca-certificates \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /app/editor
COPY editor/package.json editor/package-lock.json ./
RUN npm ci --omit=dev
COPY editor/src ./src
COPY starter /app/starter

ENV NODE_ENV=production DATA_DIR=/data STARTER_DIR=/app/starter PORT=80 HOME=/home/node
RUN mkdir -p /data && chown -R node:node /data
VOLUME /data
USER node
EXPOSE 80
CMD ["node", "src/server.js"]

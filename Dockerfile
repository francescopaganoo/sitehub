# Pannello (Modulo 2) – Coolify: build pack "Dockerfile", porta 80.
#
# Variabili d'ambiente (Coolify → Environment Variables):
#   PANEL_URL     https://pannello.agenzia.it   (indirizzo pubblico: entra negli script dei siti)
#   PANEL_KEY     32 byte in base64 (openssl rand -base64 32) – cifra le password SMTP: NON cambiarla
#   GITHUB_TOKEN  token GitHub con permesso "Contents: read and write" sui repo dei siti
#   TZ            Europe/Rome
#
# Volume persistente: /app/panel/data   (database SQLite)
# Cron (Coolify → Scheduled Tasks, ogni 5 minuti): php /app/panel/bin/cron.php

FROM php:8.4-apache

RUN a2enmod headers \
 && printf '%s\n' \
    '<VirtualHost *:80>' \
    '  DocumentRoot /app/panel/public' \
    '  <Directory /app/panel/public>' \
    '    Require all granted' \
    '    AllowOverride None' \
    '    FallbackResource /index.php' \
    '  </Directory>' \
    '  # Apache non passa l header Authorization a PHP se non lo si chiede' \
    '  SetEnvIf Authorization "(.+)" HTTP_AUTHORIZATION=$1' \
    '  ServerSignature Off' \
    '</VirtualHost>' > /etc/apache2/sites-available/000-default.conf \
 && echo 'ServerTokens Prod' >> /etc/apache2/apache2.conf \
 && echo 'expose_php = Off' > /usr/local/etc/php/conf.d/panel.ini

WORKDIR /app
COPY lib/ lib/
COPY panel/ panel/
RUN mkdir -p panel/data && chown -R www-data:www-data panel/data

ENV TZ=Europe/Rome
VOLUME /app/panel/data

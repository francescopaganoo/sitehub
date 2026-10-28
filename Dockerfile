# SiteHub (pannello dei siti statici) – Coolify: build pack "Dockerfile", porta 80.
#
# Variabili d'ambiente (Coolify → Environment Variables):
#   PANEL_URL     https://hub.planstudios.it   (indirizzo pubblico: entra negli script dei siti)
#   PANEL_KEY     32 byte in base64 (openssl rand -base64 32) – cifra le password SMTP: NON cambiarla
#   GITHUB_TOKEN  token GitHub con permesso "Contents: read and write" sui repo dei siti
#   TZ            Europe/Rome
#
# Volume persistente: /app/panel/data   (database SQLite)
# Cron (Coolify → Scheduled Tasks, ogni 5 minuti): php /app/panel/bin/cron.php

FROM php:8.4-apache

# immagini del blog: GD con JPEG e WebP, EXIF per raddrizzare le foto del telefono
RUN apt-get update \
 && apt-get install -y --no-install-recommends libjpeg62-turbo-dev libpng-dev libwebp-dev \
 && docker-php-ext-configure gd --with-jpeg --with-webp \
 && docker-php-ext-install -j"$(nproc)" gd exif \
 && rm -rf /var/lib/apt/lists/*

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
 && printf '%s\n' 'expose_php = Off' 'display_errors = Off' 'log_errors = On' \
    'upload_max_filesize = 16M' 'post_max_size = 20M' 'memory_limit = 512M' > /usr/local/etc/php/conf.d/panel.ini

WORKDIR /app
COPY lib/ lib/
COPY panel/ panel/
# guida per chi realizza i siti: /guida.md e /e/v1/{chiave}/guida.md (classe Panel\Guide)
COPY docs/GUIDA-SITI.md docs/GUIDA-SITI.md
RUN mkdir -p panel/data && chown -R www-data:www-data panel/data

ENV TZ=Europe/Rome
VOLUME /app/panel/data

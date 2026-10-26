<?php
declare(strict_types=1);

/**
 * File per mettere online un sito con Coolify (build pack "Dockerfile"):
 *   Dockerfile   nginx con solo la cartella public/
 *   nginx.conf   404, cache, compressione, redirect dai vecchi indirizzi WordPress
 *   README.md    istruzioni per chi apre il repo
 */
final class DeployFiles
{
    public static function write(string $root, array $site): void
    {
        $root = rtrim($root, '/');
        file_put_contents("$root/Dockerfile", <<<DOCKER
        # Sito statico servito da nginx (Coolify: build pack "Dockerfile", porta 80)
        FROM nginx:1.27-alpine
        COPY nginx.conf /etc/nginx/conf.d/default.conf
        COPY public/ /usr/share/nginx/html/

        DOCKER);
        file_put_contents("$root/.dockerignore", "content/\ntemplates/\n*.md\n");
        file_put_contents("$root/nginx.conf", self::nginx($site));
        if (!is_file("$root/README.md")) {
            file_put_contents("$root/README.md", self::readme($site));
        }
    }

    private static function nginx(array $site): string
    {
        // vecchi percorsi WordPress → nuovi (immagini indicizzate, link da altri siti)
        $moved = $site['moved_paths'] ?? ['/wp-content/uploads/' => '/media/'];
        $rules = '';
        foreach ($moved as $from => $to) {
            if (str_starts_with($from, '/wp-')) {
                $rules .= sprintf("    location ^~ %s { rewrite ^%s(.*)$ %s\$1 permanent; }\n", $from, preg_quote($from), $to);
            }
        }
        foreach ($site['redirects'] ?? [] as $from => $to) {
            $rules .= sprintf("    location = %s { return 301 %s; }\n", $from, $to);
        }

        return <<<NGINX
        server {
            listen 80;
            server_name _;
            root /usr/share/nginx/html;
            index index.html;

            # dietro il proxy di Coolify: redirect relativi (niente http:// al posto di https://)
            absolute_redirect off;
            server_tokens off;

            gzip on;
            gzip_min_length 1024;
            gzip_types text/css application/javascript image/svg+xml application/json text/xml text/plain;

            add_header X-Content-Type-Options "nosniff" always;
            add_header Referrer-Policy "strict-origin-when-cross-origin" always;
            add_header X-Frame-Options "SAMEORIGIN" always;

            # indirizzi di WordPress che non esistono più
        $rules    location ~ ^/(wp-admin|wp-login\.php|xmlrpc\.php|wp-json|wp-cron\.php) { return 404; }
            location ~ ^/(comments/)?feed/?$ { return 301 /; }

            # risorse: cache lunga (il CSS ha la versione nell'URL)
            location ~ ^/(assets|media)/ {
                expires 30d;
                add_header Cache-Control "public" always;
                add_header X-Content-Type-Options "nosniff" always;
                try_files \$uri =404;
            }

            location / {
                try_files \$uri \$uri/ \$uri.html =404;
            }

            error_page 404 /404.html;
        }

        NGINX;
    }

    private static function readme(array $site): string
    {
        $name = $site['site_name'] ?? $site['slug'] ?? 'Sito';
        return <<<MD
        # $name

        Sito statico gestito dal pannello dell'agenzia.

        - `public/` – il sito pubblicato (Coolify fa il deploy a ogni push)
        - `templates/`, `content/` – template e articoli del blog: **li aggiorna il pannello**, non modificarli a mano
        - `Dockerfile`, `nginx.conf` – configurazione del server

        Le modifiche a pagine e stile si fanno in `public/` (HTML e `public/assets/style.css`).

        MD;
    }
}

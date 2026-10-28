<?php
declare(strict_types=1);

namespace Panel;

use Panel\Api\SitesApi;
use Panel\Forms\FormService;
use Panel\Http\ApiError;
use Panel\Http\Request;
use Panel\Http\Response;

/**
 * Endpoint pubblici usati dai siti (senza token: la chiave pk_… nell'HTML identifica il sito).
 *   GET     /e/v1/{pk}.js       script da includere nel sito: config + moduli attivi
 *   POST    /e/v1/{pk}/submit   invio di un form
 *   GET     /e/v1/{pk}/guida.md guida su misura per chi realizza il sito (moduli attivi, chiave già inserita)
 *   GET     /e/v1/{pk}/media/{id}/{larghezza}.webp  immagini del blog prima della pubblicazione (editor, anteprima)
 *
 * La chiave è pubblica: la sicurezza viene dai domini ammessi, dai moduli attivi e dall'antispam.
 */
final class Embed
{
    public const VERSION = 'v1';

    /** Moduli JS: file in panel/assets/embed/, inclusi solo se attivi sul sito. */
    private const MODULES = ['forms' => 'forms.js', 'cookies' => 'cookies.js'];

    public function __construct(private Store $store, private FormService $forms, private Media $media)
    {
    }

    public function handle(Request $req, string $path): Response
    {
        if (preg_match('~^/(pk_[a-f0-9]+)\.js$~', $path, $m) && $req->method === 'GET') {
            return $this->script($m[1], $req);
        }
        if (preg_match('~^/(pk_[a-f0-9]+)/guida\.md$~', $path, $m) && in_array($req->method, ['GET', 'HEAD'], true)) {
            $site = $this->store->siteByKey($m[1]) ?? throw ApiError::notFound('Sito');
            return self::guide(Guide::forSite($site), "guida-{$site['slug']}.md");
        }
        if (preg_match('~^/(pk_[a-f0-9]+)/media/([a-f0-9]{12})/(\d{2,4}|master)\.webp$~', $path, $m) && in_array($req->method, ['GET', 'HEAD'], true)) {
            return $this->media($m[1], $m[2], $m[3]);
        }
        if (preg_match('~^/(pk_[a-f0-9]+)/submit$~', $path, $m)) {
            return match ($req->method) {
                'POST'    => $this->submit($m[1], $req),
                'OPTIONS' => $this->preflight($m[1], $req),
                default   => throw new ApiError(405, 'method_not_allowed', 'Metodo non consentito'),
            };
        }
        throw new ApiError(404, 'not_found', 'Endpoint non trovato');
    }

    /** Testo semplice (si legge nel browser e lo legge un LLM), sempre aggiornato, scaricabile anche dal gestionale. */
    public static function guide(string $text, string $filename): Response
    {
        return new Response(200, $text, [
            'Content-Type'                => 'text/plain; charset=utf-8',
            'Content-Disposition'         => "inline; filename=\"$filename\"",
            'Cache-Control'               => 'no-cache',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    /** Un id non cambia mai contenuto: cache lunghissima. L'id è casuale, quindi le bozze non si indovinano. */
    private function media(string $key, string $id, string $variant): Response
    {
        $site = $this->store->siteByKey($key) ?? throw ApiError::notFound('Immagine');
        $file = $this->media->file($site['id'], $id, $variant) ?? throw ApiError::notFound('Immagine');
        return new Response(200, (string) file_get_contents($file), [
            'Content-Type'                => 'image/webp',
            'Cache-Control'               => 'public, max-age=31536000, immutable',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    public static function mediaUrl(string $publicKey, string $id, int|string $variant): string
    {
        return self::baseUrl() . '/e/' . self::VERSION . "/$publicKey/media/$id/$variant.webp";
    }

    public static function scriptUrl(string $publicKey): string
    {
        return self::baseUrl() . '/e/' . self::VERSION . "/$publicKey.js";
    }

    public static function snippet(string $publicKey): string
    {
        return '<script src="' . self::scriptUrl($publicKey) . '" defer></script>';
    }

    private function script(string $key, Request $req): Response
    {
        $site = $this->store->siteByKey($key);
        if (!$site) {
            return new Response(404, "console.warn('[pannello] chiave sito non valida: $key');",
                ['Content-Type' => 'application/javascript; charset=utf-8']);
        }
        $modules = array_filter($site['modules'] ?? []);
        $config = [
            'site'      => $site['slug'],
            'submitUrl' => self::baseUrl() . '/e/' . self::VERSION . "/$key/submit",
            'modules'   => $modules,
        ];
        if (!empty($modules['cookies'])) {
            $config['cookies'] = SitesApi::cookieSettings($site);
        }

        $js = "/* pannello embed " . self::VERSION . " - {$site['slug']} */\n(function (SSG) {\n'use strict';\n";
        foreach (self::MODULES as $name => $file) {
            if (!empty($modules[$name])) {
                $js .= file_get_contents(__DIR__ . "/../assets/embed/$file") . "\n";
            }
        }
        $js .= '})(' . json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ");\n";

        // cache breve: attivare/disattivare un modulo ha effetto in pochi minuti
        return new Response(200, $js, [
            'Content-Type'                => 'application/javascript; charset=utf-8',
            'Cache-Control'               => 'public, max-age=300',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    private function submit(string $key, Request $req): Response
    {
        $site = $this->store->siteByKey($key);
        $fromScript = !preg_match('~application/x-www-form-urlencoded|multipart/form-data~', $req->header('content-type') ?? '');
        $origin = $this->origin($req);

        try {
            if (!$site) {
                throw ApiError::notFound('Sito');
            }
            if (empty($site['modules']['forms'])) {
                throw new ApiError(403, 'module_disabled', 'Modulo form non attivo per questo sito');
            }
            if (!$origin || !$this->allowed($site, $origin)) {
                throw new ApiError(403, 'origin_not_allowed', 'Dominio non autorizzato per questo sito');
            }
            $input = $fromScript ? (json_decode($req->rawBody(), true) ?: []) : $req->input();
            $payload = FormService::normalize($input, $fromScript, $req->header('referer') ?? '');
            $result = $this->forms->submit($site, $payload, self::clientIp(), $req->header('user-agent') ?? '');
        } catch (ApiError $e) {
            if ($fromScript) {
                return Response::error($e)->withHeaders($this->cors($origin, $site));
            }
            return Response::html('<!doctype html><meta charset="utf-8"><title>Errore</title>'
                . '<p>' . htmlspecialchars($e->getMessage()) . '</p><p><a href="javascript:history.back()">Torna indietro</a></p>', $e->status);
        }

        if (!$fromScript) {
            // form senza JavaScript: si torna alla pagina di partenza
            $back = $req->header('referer') ?: $site['domain'];
            return Response::redirect(strtok($back, '#') . '#inviato');
        }
        return Response::json(['ok' => true, 'message' => $result['message'] ?: null])->withHeaders($this->cors($origin, $site));
    }

    private function preflight(string $key, Request $req): Response
    {
        $site = $this->store->siteByKey($key);
        $origin = $this->origin($req);
        return (new Response(204, ''))->withHeaders($this->cors($origin, $site) + [
            'Access-Control-Allow-Methods' => 'POST',
            'Access-Control-Allow-Headers' => 'Content-Type',
            'Access-Control-Max-Age'       => '86400',
        ]);
    }

    private function cors(?string $origin, ?array $site): array
    {
        return $origin && $site && $this->allowed($site, $origin)
            ? ['Access-Control-Allow-Origin' => $origin, 'Vary' => 'Origin']
            : ['Vary' => 'Origin'];
    }

    /** Origin della richiesta; i form senza JavaScript a volte mandano solo il Referer. */
    private function origin(Request $req): ?string
    {
        $o = $req->header('origin');
        if (!$o || $o === 'null') {
            $ref = parse_url($req->header('referer') ?? '');
            $o = isset($ref['scheme'], $ref['host']) ? $ref['scheme'] . '://' . $ref['host'] . (isset($ref['port']) ? ':' . $ref['port'] : '') : null;
        }
        return $o;
    }

    /** Dominio del sito (con e senza www) + domini extra configurati (staging, anteprime Coolify). */
    private function allowed(array $site, string $origin): bool
    {
        $host = strtolower(parse_url($origin, PHP_URL_HOST) ?? '');
        $port = parse_url($origin, PHP_URL_PORT);
        $hostPort = $host . ($port ? ":$port" : '');
        $main = preg_replace('~^www\.~', '', strtolower(parse_url($site['domain'], PHP_URL_HOST) ?? ''));
        $allowed = [$main, "www.$main"];
        foreach ($site['origins'] ?? [] as $extra) {
            $allowed[] = strtolower(preg_replace('~^https?://~', '', rtrim($extra, '/')));
        }
        return in_array($host, $allowed, true) || in_array($hostPort, $allowed, true);
    }

    public static function baseUrl(): string
    {
        $env = getenv('PANEL_URL');
        if ($env) {
            return rtrim($env, '/');
        }
        $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http');
        return $proto . '://' . ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    /** Dietro il proxy di Coolify l'IP reale arriva in X-Forwarded-For. */
    private static function clientIp(): string
    {
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        return $xff !== '' ? trim(explode(',', $xff)[0]) : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }
}

<?php
declare(strict_types=1);

namespace Panel\Api;

use Panel\Auth;
use Panel\Embed;
use Panel\Http\ApiError;
use Panel\Http\Request;
use Panel\Http\Response;
use Panel\Http\Router;
use Panel\Publish\Layout;
use Panel\Slug;

final class SitesApi extends Api
{
    /** Impostazioni del blog modificabili via API. */
    private const BLOG_FIELDS = ['index_path', 'index_title', 'per_page', 'permalink', 'category_path'];

    /** Categorie di cookie che richiedono consenso (i tecnici sono sempre ammessi). */
    public const COOKIE_CATEGORIES = ['statistics', 'marketing'];

    /** Moduli attivabili per sito. */
    private const MODULES = ['forms', 'cookies', 'blog'];

    public function routes(Router $r): void
    {
        $r->add('GET', '/sites', fn(Request $q) => $this->index());
        $r->add('POST', '/sites', fn(Request $q) => $this->create($q));
        $r->add('GET', '/sites/{site}', fn(Request $q) => Response::json(['data' => $this->out($this->site($q->param('site')))]));
        $r->add('PATCH', '/sites/{site}', fn(Request $q) => $this->update($q));
        $r->add('DELETE', '/sites/{site}', fn(Request $q) => $this->delete($q));
        $r->add('POST', '/sites/{site}/import', fn(Request $q) => $this->import($q));
        $r->add('POST', '/sites/{site}/publish', fn(Request $q) => $this->publish($q));
        $r->add('GET', '/sites/{site}/publications', fn(Request $q) => $this->publications($q));
        $r->add('POST', '/sites/{site}/rotate-key', fn(Request $q) => $this->rotateKey($q));
    }

    private function index(): Response
    {
        return Response::json(['data' => array_map(fn($s) => $this->out($s), $this->store->sites($this->principal['site_id']))]);
    }

    private function create(Request $req): Response
    {
        Auth::requireAgency($this->principal);
        $in = $req->input();
        $errors = [];
        $slug = (string) ($in['slug'] ?? '');
        if (!Slug::valid($slug)) {
            $errors['slug'] = 'Obbligatorio: lettere minuscole, numeri e trattini';
        } elseif (array_filter($this->store->sites(), fn($s) => $s['slug'] === $slug)) {
            $errors['slug'] = 'Esiste già un sito con questo slug';
        }
        if (trim((string) ($in['name'] ?? '')) === '') {
            $errors['name'] = 'Obbligatorio';
        }
        $domain = rtrim((string) ($in['domain'] ?? ''), '/');
        if (!preg_match('~^https?://[^/\s]+$~', $domain)) {
            $errors['domain'] = 'Obbligatorio, es. https://www.sito.it';
        }
        $target = $this->validTarget($in['target'] ?? null, $errors);
        $modules = $this->validModules($in['modules'] ?? [], array_fill_keys(self::MODULES, true), $errors);
        $origins = $this->validOrigins($in['origins'] ?? [], $errors);
        if ($errors) {
            throw ApiError::validation($errors);
        }

        $site = $this->store->createSite(['slug' => $slug, 'name' => trim($in['name']), 'domain' => $domain, 'target' => $target,
            'config' => ['auto_publish' => (bool) ($in['auto_publish'] ?? true)], 'public_key' => self::newKey(),
            'modules' => $modules, 'origins' => $origins]);
        return Response::json(['data' => $this->out($site)], 201);
    }

    private function update(Request $req): Response
    {
        Auth::requireAgency($this->principal);
        $site = $this->site($req->param('site'));
        $in = $req->input();
        $set = [];
        $errors = [];

        if (array_key_exists('name', $in)) {
            $set['name'] = trim((string) $in['name']);
            if ($set['name'] === '') {
                $errors['name'] = 'Non può essere vuoto';
            }
        }
        if (array_key_exists('domain', $in)) {
            $set['domain'] = rtrim((string) $in['domain'], '/');
            if (!preg_match('~^https?://[^/\s]+$~', $set['domain'])) {
                $errors['domain'] = 'Formato: https://www.sito.it';
            }
        }
        if (array_key_exists('target', $in)) {
            $set['target'] = $this->validTarget($in['target'], $errors);
        }
        if (array_key_exists('modules', $in)) {
            $set['modules'] = $this->validModules($in['modules'], $site['modules'], $errors);
        }
        if (array_key_exists('origins', $in)) {
            $set['origins'] = $this->validOrigins($in['origins'], $errors);
        }
        $config = $site['config'];
        if (array_key_exists('auto_publish', $in)) {
            $config['auto_publish'] = (bool) $in['auto_publish'];
        }
        foreach (array_intersect_key($in['blog'] ?? [], array_flip(self::BLOG_FIELDS)) as $k => $v) {
            $config['blog'][$k] = match ($k) {
                'per_page' => max(1, min(100, (int) $v)),
                'index_path' => $v === null || $v === '' ? null : '/' . trim((string) $v, '/') . '/',
                default => (string) $v,
            };
        }
        if (array_key_exists('cookies', $in)) {
            $config['cookies'] = $this->validCookies($in['cookies'], $config['cookies'] ?? [], $errors);
        }
        if (isset($config['blog']['permalink']) && !str_contains($config['blog']['permalink'], '{slug}')) {
            $errors['blog.permalink'] = 'Deve contenere {slug}';
        }
        if (isset($in['blog']) && ($config['layout'] ?? null) === 'static' && ($error = Layout::staticBlogError($config['blog'] ?? []))) {
            $errors['blog'] = $error;
        }
        if ($errors) {
            throw ApiError::validation($errors);
        }
        $set['config'] = $config;

        $this->store->updateSite($site['id'], $set);
        $site = $this->site($site['slug']);
        $publish = isset($in['blog']) && $site['templates'] ? $this->autoPublish($site, 'Pannello: impostazioni blog aggiornate') : null;
        return Response::json(['data' => $this->out($site), 'publish' => $publish]);
    }

    /** Elimina il sito da SiteHub. Il repo e il sito pubblicato non vengono toccati. */
    private function delete(Request $req): Response
    {
        Auth::requireAgency($this->principal);
        $site = $this->site($req->param('site'));
        $this->store->deleteSite($site['id']);
        return Response::json(['data' => ['deleted' => $site['slug']]]);
    }

    private function import(Request $req): Response
    {
        Auth::requireAgency($this->principal);
        $site = $this->site($req->param('site'));
        $replace = filter_var($req->input()['replace'] ?? $req->query['replace'] ?? false, FILTER_VALIDATE_BOOL);
        $result = $this->publisher->import($site, $replace);
        // sito fatto a mano: le pagine del blog (elenco, sitemap-blog.xml) non esistono ancora, si pubblicano subito
        $publish = $result['layout'] === 'static'
            ? $this->autoPublish($this->site($site['slug']), 'Pannello: blog collegato', true)
            : null;
        return Response::json(['data' => $result, 'publish' => $publish]);
    }

    private function publish(Request $req): Response
    {
        $site = $this->site($req->param('site'));
        $message = trim((string) ($req->input()['message'] ?? '')) ?: 'Pannello: pubblicazione';
        return Response::json(['data' => $this->publisher->publish($site, $this->principal['name'], $message)]);
    }

    private function publications(Request $req): Response
    {
        $site = $this->site($req->param('site'));
        return Response::json(['data' => $this->store->publications($site['id'], $req->int('limit', 20, 1, 100))]);
    }

    /** Nuova chiave: l'HTML con la vecchia smette di funzionare finché non si aggiorna lo script. */
    private function rotateKey(Request $req): Response
    {
        Auth::requireAgency($this->principal);
        $site = $this->site($req->param('site'));
        $this->store->updateSite($site['id'], ['public_key' => self::newKey()]);
        return Response::json(['data' => $this->out($this->site($site['slug']))]);
    }

    private static function newKey(): string
    {
        return 'pk_' . bin2hex(random_bytes(12));
    }

    private function validModules(mixed $in, array $current, array &$errors): array
    {
        if (!is_array($in) || array_diff_key($in, array_flip(self::MODULES))) {
            $errors['modules'] = 'Oggetto con chiavi ' . implode(', ', self::MODULES) . ' e valori true/false';
            return $current;
        }
        foreach ($in as $k => $v) {
            $current[$k] = (bool) $v;
        }
        return $current;
    }

    /**
     * Banner cookie: aggiorna solo le chiavi inviate.
     * "reset_consent": true incrementa la versione, così il banner ricompare a tutti i visitatori.
     */
    private function validCookies(mixed $in, array $current, array &$errors): array
    {
        if (!is_array($in)) {
            $errors['cookies'] = 'Oggetto: policy_url, categories, text, color, button, reset_consent';
            return $current;
        }
        if (array_key_exists('policy_url', $in)) {
            $url = trim((string) $in['policy_url']);
            if ($url !== '' && !preg_match('~^(/|https?://)\S*$~', $url)) {
                $errors['cookies.policy_url'] = 'Percorso (/cookie-policy/) o indirizzo completo';
            }
            $current['policy_url'] = $url;
        }
        if (array_key_exists('categories', $in)) {
            $list = is_array($in['categories']) ? array_values(array_unique($in['categories'])) : null;
            if ($list === null || array_diff($list, self::COOKIE_CATEGORIES)) {
                $errors['cookies.categories'] = 'Lista con valori ' . implode(', ', self::COOKIE_CATEGORIES);
            } else {
                $current['categories'] = $list;
            }
        }
        if (array_key_exists('text', $in)) {
            $current['text'] = trim(strip_tags((string) $in['text']));
            if (mb_strlen($current['text']) > 600) {
                $errors['cookies.text'] = 'Massimo 600 caratteri';
            }
        }
        if (array_key_exists('color', $in)) {
            $current['color'] = trim((string) $in['color']);
            if ($current['color'] !== '' && !preg_match('~^#[0-9a-f]{6}$~i', $current['color'])) {
                $errors['cookies.color'] = 'Colore esadecimale, es. #1d4ed8';
            }
        }
        if (array_key_exists('button', $in)) {
            $current['button'] = (string) $in['button'];
            if (!in_array($current['button'], ['left', 'right', 'none'], true)) {
                $errors['cookies.button'] = 'Valori ammessi: left, right, none';
            }
        }
        if (!empty($in['reset_consent'])) {
            $current['version'] = ($current['version'] ?? 1) + 1;
        }
        return $current;
    }

    /** Impostazioni del banner cookie con i valori predefiniti. */
    public static function cookieSettings(array $site): array
    {
        $c = $site['config']['cookies'] ?? [];
        return [
            'policy_url' => ($c['policy_url'] ?? '') !== '' ? $c['policy_url'] : '/cookie-policy/',
            'categories' => $c['categories'] ?? [],
            'text'       => $c['text'] ?? '',
            'color'      => $c['color'] ?? '',
            'button'     => $c['button'] ?? 'left',
            'version'    => $c['version'] ?? 1,
        ];
    }

    /** Domini extra da cui i form sono accettati (es. anteprime Coolify). Il dominio del sito è sempre ammesso. */
    private function validOrigins(mixed $in, array &$errors): array
    {
        $out = [];
        foreach (is_array($in) ? $in : [null] as $o) {
            $host = strtolower(preg_replace('~^https?://~', '', rtrim(trim((string) $o), '/')));
            if (!preg_match('~^[a-z0-9.-]+(:\d+)?$~', $host)) {
                $errors['origins'] = 'Lista di domini, es. ["staging.sito.it", "localhost:8080"]';
                return [];
            }
            $out[] = $host;
        }
        return array_values(array_unique($out));
    }

    private function validTarget(mixed $t, array &$errors): array
    {
        if (!is_array($t) || !in_array($t['type'] ?? null, ['github', 'local'], true)) {
            $errors['target'] = 'Obbligatorio: {"type":"github","repo":"org/nome","branch":"main"} oppure {"type":"local","path":"…"}';
            return [];
        }
        if ($t['type'] === 'github') {
            if (!preg_match('~^[\w.-]+/[\w.-]+$~', (string) ($t['repo'] ?? ''))) {
                $errors['target.repo'] = 'Formato: proprietario/nome';
            }
            return ['type' => 'github', 'repo' => (string) ($t['repo'] ?? ''), 'branch' => (string) ($t['branch'] ?? 'main')];
        }
        if (!is_dir((string) ($t['path'] ?? ''))) {
            $errors['target.path'] = 'Cartella inesistente sul server del pannello';
        }
        return ['type' => 'local', 'path' => (string) ($t['path'] ?? '')];
    }

    private function out(array $site): array
    {
        return [
            'slug'         => $site['slug'],
            'name'         => $site['name'],
            'domain'       => $site['domain'],
            'target'       => $site['target'],
            'auto_publish' => $site['config']['auto_publish'] ?? true,
            'public_key'   => $site['public_key'],
            'embed'        => ['script_url' => Embed::scriptUrl($site['public_key']), 'snippet' => Embed::snippet($site['public_key'])],
            'modules'      => $site['modules'],
            'origins'      => $site['origins'],
            'cookies'      => self::cookieSettings($site),
            'blog'         => array_intersect_key($site['config']['blog'] ?? [], array_flip(self::BLOG_FIELDS)) ?: null,
            'imported'     => !empty($site['templates']),
            'layout'       => $site['config']['layout'] ?? null, // clone | static (dopo l'import del blog)
            'submissions'  => $this->store->submissionStats($site['id']),
            'published_at' => $site['published_at'],
            'created_at'   => $site['created_at'],
            'updated_at'   => $site['updated_at'],
        ];
    }
}

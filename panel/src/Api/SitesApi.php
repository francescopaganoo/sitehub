<?php
declare(strict_types=1);

namespace Panel\Api;

use Panel\Auth;
use Panel\Http\ApiError;
use Panel\Http\Request;
use Panel\Http\Response;
use Panel\Http\Router;
use Panel\Slug;

final class SitesApi extends Api
{
    /** Impostazioni del blog modificabili via API. */
    private const BLOG_FIELDS = ['index_path', 'index_title', 'per_page', 'permalink', 'category_path'];

    public function routes(Router $r): void
    {
        $r->add('GET', '/sites', fn(Request $q) => $this->index());
        $r->add('POST', '/sites', fn(Request $q) => $this->create($q));
        $r->add('GET', '/sites/{site}', fn(Request $q) => Response::json(['data' => $this->out($this->site($q->param('site')))]));
        $r->add('PATCH', '/sites/{site}', fn(Request $q) => $this->update($q));
        $r->add('POST', '/sites/{site}/import', fn(Request $q) => $this->import($q));
        $r->add('POST', '/sites/{site}/publish', fn(Request $q) => $this->publish($q));
        $r->add('GET', '/sites/{site}/publications', fn(Request $q) => $this->publications($q));
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
        if ($errors) {
            throw ApiError::validation($errors);
        }

        $site = $this->store->createSite(['slug' => $slug, 'name' => trim($in['name']), 'domain' => $domain, 'target' => $target,
            'config' => ['auto_publish' => (bool) ($in['auto_publish'] ?? true)]]);
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
        if (isset($config['blog']['permalink']) && !str_contains($config['blog']['permalink'], '{slug}')) {
            $errors['blog.permalink'] = 'Deve contenere {slug}';
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

    private function import(Request $req): Response
    {
        Auth::requireAgency($this->principal);
        $site = $this->site($req->param('site'));
        $replace = filter_var($req->input()['replace'] ?? $req->query['replace'] ?? false, FILTER_VALIDATE_BOOL);
        $result = $this->publisher->import($site, $replace);
        return Response::json(['data' => $result]);
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
            'blog'         => array_intersect_key($site['config']['blog'] ?? [], array_flip(self::BLOG_FIELDS)) ?: null,
            'imported'     => !empty($site['templates']),
            'published_at' => $site['published_at'],
            'created_at'   => $site['created_at'],
            'updated_at'   => $site['updated_at'],
        ];
    }
}

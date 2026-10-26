<?php
declare(strict_types=1);

namespace Panel\Api;

use Panel\Http\ApiError;
use Panel\Http\Request;
use Panel\Http\Response;
use Panel\Http\Router;
use Panel\Slug;

final class CategoriesApi extends Api
{
    public function routes(Router $r): void
    {
        $r->add('GET', '/sites/{site}/categories', fn(Request $q) => $this->index($q));
        $r->add('POST', '/sites/{site}/categories', fn(Request $q) => $this->save($q, null));
        $r->add('GET', '/sites/{site}/categories/{slug}', fn(Request $q) => $this->show($q));
        $r->add('PATCH', '/sites/{site}/categories/{slug}', fn(Request $q) => $this->save($q, $q->param('slug')));
        $r->add('DELETE', '/sites/{site}/categories/{slug}', fn(Request $q) => $this->delete($q));
    }

    private function index(Request $req): Response
    {
        $site = $this->site($req->param('site'));
        $counts = $this->counts($site['id']);
        return Response::json(['data' => array_map(fn($c) => $c + ['posts' => $counts[$c['slug']] ?? 0],
            $this->store->categories($site['id']))]);
    }

    private function show(Request $req): Response
    {
        $site = $this->site($req->param('site'));
        $cat = $this->store->category($site['id'], $req->param('slug'));
        return Response::json(['data' => $cat + ['posts' => $this->counts($site['id'])[$cat['slug']] ?? 0]]);
    }

    private function save(Request $req, ?string $slug): Response
    {
        $site = $this->site($req->param('site'));
        $existing = $slug === null ? null : $this->store->category($site['id'], $slug);
        $in = $req->input();
        $errors = [];

        $cat = $existing ?? ['name' => '', 'description' => '', 'seo' => []];
        if (array_key_exists('name', $in) || !$existing) {
            $cat['name'] = trim(strip_tags((string) ($in['name'] ?? '')));
            if ($cat['name'] === '') {
                $errors['name'] = 'Obbligatorio';
            }
        }
        $cat['slug'] = (string) ($in['slug'] ?? $existing['slug'] ?? Slug::make($cat['name']));
        $taken = array_filter($this->store->categories($site['id']), fn($c) => $c['slug'] === $cat['slug'] && $c['slug'] !== $slug);
        if (!Slug::valid($cat['slug'])) {
            $errors['slug'] = 'Solo lettere minuscole, numeri e trattini';
        } elseif ($taken) {
            $errors['slug'] = 'Esiste già una categoria con questo slug';
        }
        if (array_key_exists('description', $in)) {
            $cat['description'] = trim(strip_tags((string) $in['description']));
        }
        if (array_key_exists('seo', $in)) {
            $seo = is_array($in['seo']) ? $in['seo'] : [];
            $cat['seo'] = ['title' => trim((string) ($seo['title'] ?? '')), 'description' => trim((string) ($seo['description'] ?? ''))];
        }
        if ($errors) {
            throw ApiError::validation($errors);
        }

        $this->store->saveCategory($site['id'], $cat, $slug);
        // una categoria nuova senza articoli non cambia il sito
        $publish = $existing ? $this->autoPublish($site, "Pannello: categoria aggiornata «{$cat['name']}»") : null;
        return Response::json(['data' => $this->store->category($site['id'], $cat['slug']), 'publish' => $publish], $existing ? 200 : 201);
    }

    private function delete(Request $req): Response
    {
        $site = $this->site($req->param('site'));
        $cat = $this->store->category($site['id'], $req->param('slug'));
        $this->store->deleteCategory($site['id'], $cat['slug']);
        $publish = $this->autoPublish($site, "Pannello: categoria eliminata «{$cat['name']}»");
        return Response::json(['data' => ['deleted' => $cat['slug']], 'publish' => $publish]);
    }

    private function counts(int $siteId): array
    {
        $counts = [];
        foreach ($this->store->allPosts($siteId) as $p) {
            foreach ($p['categories'] as $slug) {
                $counts[$slug] = ($counts[$slug] ?? 0) + 1;
            }
        }
        return $counts;
    }
}

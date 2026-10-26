<?php
declare(strict_types=1);

namespace Panel\Api;

use Panel\Http\ApiError;
use Panel\Http\Request;
use Panel\Http\Response;
use Panel\Http\Router;
use Panel\Sanitizer;
use Panel\Slug;

final class PostsApi extends Api
{
    public function routes(Router $r): void
    {
        $r->add('GET', '/sites/{site}/posts', fn(Request $q) => $this->index($q));
        $r->add('POST', '/sites/{site}/posts', fn(Request $q) => $this->save($q, null));
        $r->add('GET', '/sites/{site}/posts/{id}', fn(Request $q) => $this->show($q));
        $r->add('PATCH', '/sites/{site}/posts/{id}', fn(Request $q) => $this->save($q, $q->param('id')));
        $r->add('DELETE', '/sites/{site}/posts/{id}', fn(Request $q) => $this->delete($q));
        $r->add('GET', '/sites/{site}/posts/{id}/preview', fn(Request $q) => $this->preview($q));
    }

    private function index(Request $req): Response
    {
        $site = $this->site($req->param('site'));
        $page = $req->int('page', 1);
        $perPage = $req->int('per_page', 20, 1, 100);
        $filters = array_intersect_key($req->query, array_flip(['status', 'category', 'q']));
        [$posts, $total] = $this->store->posts($site['id'], $filters, $page, $perPage);

        return Response::json([
            'data' => array_map(fn($p) => $this->out($site, $p, false), $posts),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'pages' => (int) ceil($total / $perPage)],
        ]);
    }

    private function show(Request $req): Response
    {
        $site = $this->site($req->param('site'));
        return Response::json(['data' => $this->out($site, $this->store->post($site['id'], $req->param('id')))]);
    }

    /** POST crea, PATCH aggiorna solo i campi inviati. */
    private function save(Request $req, ?string $id): Response
    {
        $site = $this->site($req->param('site'));
        $existing = $id === null ? null : $this->store->post($site['id'], $id);
        $post = $this->validate($site, $req->input(), $existing);
        $this->store->savePost($site['id'], $post);

        // pubblica se l'articolo è (o era) visibile sul sito
        $visible = $post['status'] === 'published' || ($existing['status'] ?? null) === 'published';
        $verb = $existing ? 'aggiornato' : 'creato';
        $publish = $visible ? $this->autoPublish($site, "Pannello: articolo $verb «{$post['title']}»") : null;

        return Response::json(['data' => $this->out($site, $this->store->post($site['id'], $post['id'])), 'publish' => $publish],
            $existing ? 200 : 201);
    }

    private function delete(Request $req): Response
    {
        $site = $this->site($req->param('site'));
        $post = $this->store->post($site['id'], $req->param('id'));
        $this->store->deletePost($site['id'], $post['id']);
        $publish = $post['status'] === 'published' ? $this->autoPublish($site, "Pannello: articolo eliminato «{$post['title']}»") : null;
        return Response::json(['data' => ['deleted' => $post['id']], 'publish' => $publish]);
    }

    private function preview(Request $req): Response
    {
        $site = $this->site($req->param('site'));
        return Response::html($this->publisher->preview($site, $this->store->post($site['id'], $req->param('id'))));
    }

    private function validate(array $site, array $in, ?array $existing): array
    {
        $errors = [];
        $now = date('Y-m-d\TH:i:s');
        $post = $existing ?? [
            'id' => bin2hex(random_bytes(6)), 'status' => 'draft', 'excerpt' => '', 'content' => '', 'categories' => [],
            'tags' => [], 'image' => null, 'author' => '', 'seo' => [], 'head_extra' => '', 'wp_id' => null,
            'date' => $now, 'created_at' => date('c'),
        ];

        if (array_key_exists('title', $in) || !$existing) {
            $post['title'] = trim(strip_tags((string) ($in['title'] ?? '')));
            if ($post['title'] === '') {
                $errors['title'] = 'Obbligatorio';
            } elseif (mb_strlen($post['title']) > 300) {
                $errors['title'] = 'Massimo 300 caratteri';
            }
        }

        if (array_key_exists('slug', $in) && $in['slug'] !== null && $in['slug'] !== '') {
            $post['slug'] = (string) $in['slug'];
            if (!Slug::valid($post['slug'])) {
                $errors['slug'] = 'Solo lettere minuscole, numeri e trattini';
            } elseif ($this->store->slugTaken($site['id'], $post['slug'], $existing['id'] ?? null)) {
                $errors['slug'] = 'Già usato da un altro articolo';
            }
        } elseif (!$existing && $post['title'] !== '') {
            $post['slug'] = $this->uniqueSlug($site['id'], Slug::make($post['title']));
        }

        if (array_key_exists('status', $in)) {
            $post['status'] = (string) $in['status'];
            if (!in_array($post['status'], ['draft', 'published'], true)) {
                $errors['status'] = 'Valori ammessi: draft, published';
            }
        }

        if (array_key_exists('date', $in)) {
            $t = strtotime((string) $in['date']);
            if ($t === false) {
                $errors['date'] = 'Data non valida (ISO 8601, es. 2026-10-26T09:00:00+01:00)';
            } else {
                $post['date'] = date('Y-m-d\TH:i:s', $t);
            }
        }

        if (array_key_exists('content', $in)) {
            $post['content'] = Sanitizer::html((string) $in['content']);
        }
        if (array_key_exists('excerpt', $in)) {
            $post['excerpt'] = trim(strip_tags((string) $in['excerpt']));
        }
        if (array_key_exists('author', $in)) {
            $post['author'] = trim(strip_tags((string) $in['author']));
        }

        if (array_key_exists('categories', $in)) {
            $known = array_column($this->store->categories($site['id']), 'slug');
            $cats = is_array($in['categories']) ? array_values(array_unique(array_map('strval', $in['categories']))) : null;
            if ($cats === null) {
                $errors['categories'] = 'Deve essere una lista di slug di categoria';
            } elseif ($unknown = array_diff($cats, $known)) {
                $errors['categories'] = 'Categorie inesistenti: ' . implode(', ', $unknown);
            } else {
                $post['categories'] = $cats;
            }
        }
        if (array_key_exists('tags', $in)) {
            if (is_array($in['tags'])) {
                $post['tags'] = array_values(array_filter(array_map(fn($t) => trim(strip_tags((string) $t)), $in['tags'])));
            } else {
                $errors['tags'] = 'Deve essere una lista di testi';
            }
        }

        if (array_key_exists('image', $in)) {
            $img = $in['image'];
            if ($img === null) {
                $post['image'] = null;
            } elseif (!is_array($img) || !preg_match('~^(/[^\s]*|https://[^\s]+)$~', (string) ($img['url'] ?? ''))) {
                $errors['image'] = 'Formato: {"url": "/media/…" o "https://…", "alt": "…"} oppure null';
            } else {
                $post['image'] = ['url' => $img['url'], 'alt' => trim((string) ($img['alt'] ?? '')),
                    'width' => isset($img['width']) ? (int) $img['width'] : null, 'height' => isset($img['height']) ? (int) $img['height'] : null];
            }
        }

        if (array_key_exists('seo', $in)) {
            $seo = is_array($in['seo']) ? $in['seo'] : [];
            $post['seo'] = [
                'title'       => trim(strip_tags((string) ($seo['title'] ?? ''))),
                'description' => trim(strip_tags((string) ($seo['description'] ?? ''))),
                'noindex'     => (bool) ($seo['noindex'] ?? false),
            ];
        }

        if ($errors) {
            throw ApiError::validation($errors);
        }
        $post['modified'] = $now;
        $post['updated_at'] = date('c');
        return $post;
    }

    private function uniqueSlug(int $siteId, string $base): string
    {
        $slug = $base;
        for ($n = 2; $this->store->slugTaken($siteId, $slug); $n++) {
            $slug = "$base-$n";
        }
        return $slug;
    }

    private function out(array $site, array $post, bool $full = true): array
    {
        $url = $site['templates'] ? $this->publisher->renderer($site)->postUrl($post) : null;
        $out = [
            'id'         => $post['id'],
            'slug'       => $post['slug'],
            'title'      => $post['title'],
            'status'     => $post['status'],
            'scheduled'  => $post['status'] === 'published' && strtotime($post['date']) > time(),
            'date'       => date('c', strtotime($post['date'])),
            'modified'   => date('c', strtotime($post['modified'])),
            'url'        => $url ? $site['domain'] . $url : null,
            'excerpt'    => $post['excerpt'],
            'categories' => $post['categories'],
            'tags'       => $post['tags'],
            'image'      => $post['image'],
            'author'     => $post['author'],
            'seo'        => $post['seo'] ?: ['title' => '', 'description' => '', 'noindex' => false],
        ];
        if ($full) {
            $out['content'] = $post['content'];
        }
        return $out;
    }
}

<?php
declare(strict_types=1);

namespace Panel\Api;

use Panel\Embed;
use Panel\Http\ApiError;
use Panel\Http\Request;
use Panel\Http\Response;
use Panel\Http\Router;

/**
 * Immagini del blog: caricamento (multipart, campo "file"), libreria, testo alternativo, ritagli.
 * Negli articoli un'immagine si usa con il suo id: nel testo <img data-media="id" src="…">, come immagine
 * in evidenza "image": {"media_id": "id"}. Gli indirizzi restituiti (urls) valgono per editor e anteprime;
 * alla pubblicazione le immagini vanno nel repo del sito.
 */
final class MediaApi extends Api
{
    public function routes(Router $r): void
    {
        $r->add('GET', '/sites/{site}/media', fn(Request $q) => $this->index($q));
        $r->add('POST', '/sites/{site}/media', fn(Request $q) => $this->upload($q));
        $r->add('GET', '/sites/{site}/media/{id}', fn(Request $q) => $this->show($q));
        $r->add('PATCH', '/sites/{site}/media/{id}', fn(Request $q) => $this->update($q));
        $r->add('POST', '/sites/{site}/media/{id}/crop', fn(Request $q) => $this->crop($q));
        $r->add('DELETE', '/sites/{site}/media/{id}', fn(Request $q) => $this->delete($q));
    }

    private function index(Request $req): Response
    {
        $site = $this->site($req->param('site'));
        $usage = $this->usage($site);
        $items = $this->publisher->media->all($site['id'], trim((string) ($req->query['q'] ?? '')));
        return Response::json(['data' => array_map(fn($m) => $this->out($site, $m, $usage), $items)]);
    }

    private function show(Request $req): Response
    {
        $site = $this->site($req->param('site'));
        return Response::json(['data' => $this->out($site, $this->publisher->media->get($site['id'], $req->param('id')), $this->usage($site))]);
    }

    private function upload(Request $req): Response
    {
        $site = $this->site($req->param('site'));
        $file = $req->files['file'] ?? null;
        if (!is_array($file) || is_array($file['name'] ?? null)) {
            throw ApiError::validation(['file' => 'Invia un file nel campo "file" (multipart/form-data)']);
        }
        $media = $this->publisher->media->upload($site['id'], $file, (string) ($req->input()['alt'] ?? $_POST['alt'] ?? ''));
        return Response::json(['data' => $this->out($site, $media, $this->usage($site))], 201);
    }

    private function update(Request $req): Response
    {
        $site = $this->site($req->param('site'));
        $media = $this->publisher->media->updateAlt($site['id'], $req->param('id'), (string) ($req->input()['alt'] ?? ''));
        return Response::json(['data' => $this->out($site, $media, $this->usage($site))]);
    }

    /**
     * Body: {"x", "y", "width", "height"} in pixel dell'immagine originale: se l'id è già un ritaglio,
     * quella da cui è stato ritagliato (parent_id), così si può allargare di nuovo l'inquadratura. Risposta: la nuova immagine.
     */
    private function crop(Request $req): Response
    {
        $site = $this->site($req->param('site'));
        $media = $this->publisher->media->crop($site['id'], $req->param('id'), $req->input());
        return Response::json(['data' => $this->out($site, $media, $this->usage($site))], 201);
    }

    private function delete(Request $req): Response
    {
        $site = $this->site($req->param('site'));
        $media = $this->publisher->media->get($site['id'], $req->param('id'));
        if ($posts = $this->usage($site)[$media['id']] ?? []) {
            throw new ApiError(409, 'media_in_use', 'Immagine usata negli articoli: ' . implode(', ', array_column($posts, 'title')));
        }
        $this->publisher->media->delete($site['id'], $media['id']);
        return Response::json(['data' => ['deleted' => $media['id']]]);
    }

    /** id immagine → articoli che la usano (nel testo o come immagine in evidenza). */
    private function usage(array $site): array
    {
        $usage = [];
        foreach ($this->store->allPosts($site['id']) as $post) {
            preg_match_all('~data-media="([a-f0-9]{12})"~', (string) $post['content'], $m);
            $ids = $m[1];
            if (!empty($post['image']['media_id'])) {
                $ids[] = $post['image']['media_id'];
            }
            foreach (array_unique($ids) as $id) {
                $usage[$id][] = ['id' => $post['id'], 'title' => $post['title'], 'status' => $post['status']];
            }
        }
        return $usage;
    }

    private function out(array $site, array $media, array $usage): array
    {
        $urls = [];
        foreach ($media['widths'] as $w) {
            $urls[(string) $w] = Embed::mediaUrl($site['public_key'], $media['id'], $w);
        }
        $medium = array_values(array_filter($media['widths'], fn($w) => $w <= 960));
        return $media + [
            'url'     => $urls[(string) (end($medium) ?: max($media['widths']))],
            'thumb'   => $urls[(string) min($media['widths'])],
            'urls'    => $urls,
            'original' => Embed::mediaUrl($site['public_key'], $media['id'], 'master'), // per il ritaglio
            'used_in' => $usage[$media['id']] ?? [],
        ];
    }
}

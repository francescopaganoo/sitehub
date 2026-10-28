<?php
declare(strict_types=1);

namespace Panel\Publish;

use BlogRenderer;
use Panel\Db;
use Panel\Embed;
use Panel\Media;
use Panel\Http\ApiError;
use Panel\Store;

/**
 * Dal database al repo del sito (percorsi per un sito clonato; per uno fatto a mano vedi Layout):
 *   public/…                    pagine del blog generate dai template
 *   content/posts/{slug}.json   copia dei dati (backup versionato, leggibile senza pannello)
 *   content/categories.json
 * I file generati in precedenza e non più prodotti (articolo cancellato, slug cambiato) vengono rimossi.
 * I template si rileggono dal repo a ogni pubblicazione: chi modifica il sito li aggiorna lì.
 */
final class Publisher
{
    private const JSON = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /** Campi di un articolo salvati nel repo (stesso formato prodotto dal clonatore). */
    private const POST_FIELDS = ['id', 'wp_id', 'slug', 'status', 'title', 'excerpt', 'content', 'date', 'modified',
        'categories', 'tags', 'image', 'author', 'seo', 'head_extra'];

    public function __construct(private Store $store, private Db $db, public readonly Media $media)
    {
    }

    public static function target(array $site): Target
    {
        return match ($site['target']['type'] ?? '') {
            'github' => GitHubTarget::fromConfig($site['target']),
            'local'  => LocalTarget::fromConfig($site['target']),
            default  => throw new ApiError(422, 'invalid_target', 'Tipo di destinazione non supportato'),
        };
    }

    public function renderer(array $site): BlogRenderer
    {
        if (empty($site['templates']['single']) || empty($site['templates']['archive'])) {
            throw new ApiError(409, 'templates_missing', 'Il sito non ha template del blog: esegui prima l\'import');
        }
        return new BlogRenderer(
            ['domain' => $site['domain'], 'site_name' => $site['config']['site_name'] ?? $site['name'],
                'blog' => ($site['config']['blog'] ?? []) + ['sitemap_file' => Layout::of($site)->sitemap]],
            $site['templates']
        );
    }

    /** @return array<string,string> path nel repo → contenuto */
    public function build(array $site): array
    {
        $posts = $this->store->allPosts($site['id']);
        $categories = $this->store->categories($site['id']);
        $layout = Layout::of($site);

        // immagini: negli articoli pubblicati diventano file del sito, scritti nello stesso commit
        $images = [];
        $public = $this->withMedia($site, $posts, function (array $m, int $w) use (&$images): string {
            $path = sprintf('/assets/blog/%s/%s-%s-%d.webp', date('Y/m', strtotime($m['created_at'])), $m['name'], substr($m['id'], 0, 6), $w);
            $images[$path] = [$m['id'], $w];
            return $path;
        });

        $files = [];
        foreach ($this->renderer($site)->render($public, $categories)['files'] as $path => $html) {
            $files[$layout->pages . $path] = $html;
        }
        $now = time();
        $visible = array_filter($public, fn($p) => $p['status'] === 'published' && strtotime($p['date']) <= $now);
        $used = implode("\n", array_map(fn($p) => $p['content'] . json_encode($p['image'], JSON_UNESCAPED_SLASHES), $visible));
        foreach ($images as $path => [$id, $w]) {
            if (str_contains($used, $path) && ($file = $this->media->file($site['id'], $id, (string) $w))) {
                $files[$layout->pages . ltrim($path, '/')] = (string) file_get_contents($file);
            }
        }
        foreach ($posts as $post) {
            $files["{$layout->data}content/posts/{$post['slug']}.json"] = json_encode(self::exportPost($post), self::JSON) . "\n";
        }
        $files["{$layout->data}content/categories.json"] = json_encode($categories, self::JSON) . "\n";
        return $files;
    }

    /** @return array{revision:?string, written:int, deleted:int} */
    public function publish(array $site, string $actor, string $message): array
    {
        try {
            $site = $this->refreshTemplates($site);
            $files = $this->build($site);
            $deletes = array_values(array_diff($site['manifest'] ?? [], array_keys($files)));
            $result = self::target($site)->commit($files, $deletes, $message);
        } catch (\Throwable $e) {
            $this->log($site, $actor, $message, null, $e->getMessage());
            throw $e;
        }

        $this->store->updateSite($site['id'], ['manifest' => array_keys($files), 'published_at' => date('c')]);
        $this->log($site, $actor, $message, $result);
        return $result;
    }

    /** Template aggiornati dal repo; se sono cambiati vengono controllati e salvati. */
    private function refreshTemplates(array $site): array
    {
        if (empty($site['templates'])) {
            throw new ApiError(409, 'templates_missing', 'Il blog del sito non è ancora collegato: aggiungi i template al repo (guida, sezione Blog) e collegalo');
        }
        $layout = Layout::of($site);
        $target = self::target($site);
        $single = $target->read($layout->single);
        $archive = $target->read($layout->archive);
        if (!$single || !$archive) {
            throw new ApiError(409, 'templates_missing', "Nel repo mancano i template del blog ($layout->single, $layout->archive)");
        }
        if ($single === ($site['templates']['single'] ?? null) && $archive === ($site['templates']['archive'] ?? null)) {
            return $site;
        }
        $check = Layout::check($single, $archive, $layout);
        if ($check['errors']) {
            throw new ApiError(422, 'templates_invalid', 'Template del blog da correggere: ' . implode('; ', $check['errors']));
        }
        $site['templates'] = ['single' => $single, 'archive' => $archive];
        $this->store->updateSite($site['id'], ['templates' => $site['templates']]);
        return $site;
    }

    public function preview(array $site, array $post): string
    {
        // anteprima: immagini servite da SiteHub, come nell'editor
        [$post] = $this->withMedia($site, [$post], fn(array $m, int $w) => Embed::mediaUrl($site['public_key'], $m['id'], $w));
        return $this->renderer($site)->renderPost($post, $this->store->categories($site['id']));
    }

    /** Larghezza occupata dall'immagine nel testo, secondo la classe scelta nell'editor. */
    private const SIZES = [
        'img--piccola' => '(max-width: 600px) 50vw, 280px',
        'img--media'   => '(max-width: 600px) 100vw, 450px',
        ''             => '(max-width: 900px) 100vw, 900px',
    ];

    /**
     * Immagini caricate in SiteHub (data-media nel testo, image.media_id per quella in evidenza):
     * indirizzi da $url(immagine, larghezza), con srcset per tutte le larghezze disponibili.
     */
    private function withMedia(array $site, array $posts, callable $url): array
    {
        $cache = [];
        $find = function (string $id) use ($site, &$cache): ?array {
            if (!array_key_exists($id, $cache)) {
                try {
                    $cache[$id] = $this->media->get($site['id'], $id);
                } catch (ApiError) {
                    $cache[$id] = null; // immagine eliminata: resta l'indirizzo salvato
                }
            }
            return $cache[$id];
        };
        $srcset = fn(array $m) => implode(', ', array_map(fn($w) => $url($m, $w) . " {$w}w", $m['widths']));

        foreach ($posts as &$post) {
            if (!empty($post['image']['media_id']) && ($m = $find($post['image']['media_id']))) {
                $w = max($m['widths']);
                $post['image'] = ['url' => $url($m, $w), 'srcset' => $srcset($m), 'width' => $w,
                    'height' => (int) round($m['height'] * $w / $m['width'])] + $post['image'];
            }
            if (!str_contains((string) $post['content'], 'data-media=')) {
                continue; // testo senza immagini caricate: resta identico, byte per byte
            }
            $doc = \Dom\HTMLDocument::createFromString('<!doctype html><body>' . $post['content'], LIBXML_NOERROR, 'UTF-8');
            foreach ($doc->querySelectorAll('img[data-media]') as $img) {
                if (!$m = $find($img->getAttribute('data-media'))) {
                    continue;
                }
                $w = max($m['widths']);
                $classes = ' ' . $img->getAttribute('class') . ' ' . ($img->parentElement?->getAttribute('class') ?? '') . ' ';
                $size = '';
                foreach (array_keys(self::SIZES) as $c) {
                    if ($c !== '' && str_contains($classes, " $c ")) {
                        $size = $c;
                    }
                }
                $img->setAttribute('src', $url($m, $w));
                $img->setAttribute('srcset', $srcset($m));
                $img->setAttribute('sizes', self::SIZES[$size]);
                $img->setAttribute('width', (string) $w);
                $img->setAttribute('height', (string) round($m['height'] * $w / $m['width']));
                $img->setAttribute('loading', 'lazy');
                $img->setAttribute('decoding', 'async');
                $img->removeAttribute('data-media');
            }
            $post['content'] = $doc->body->innerHTML;
        }
        return $posts;
    }

    /**
     * Collega il blog del sito leggendo il repo:
     *   - sito clonato: site.json, templates/, content/ prodotti dal clonatore
     *   - sito fatto a mano: _sitehub/templates/articolo.html e blog.html (ed eventuali articoli già in _sitehub/content/)
     *
     * @return array{posts:int, categories:int, layout:string, warnings:string[]}
     */
    public function import(array $site, bool $replace): array
    {
        $target = self::target($site);
        $siteJson = json_decode($target->read('site.json') ?? '', true);
        $layout = is_array($siteJson) ? Layout::clone() : Layout::static();
        $single = $target->read($layout->single);
        $archive = $target->read($layout->archive);
        if (!$single || !$archive) {
            throw new ApiError(422, 'import_failed', is_array($siteJson)
                ? 'Nel repo mancano i template del blog (templates/single.html, templates/archive.html)'
                : 'Nel repo mancano i template del blog: _sitehub/templates/articolo.html e _sitehub/templates/blog.html (vedi la guida, sezione Blog)');
        }
        $check = Layout::check($single, $archive, $layout);
        if ($check['errors']) {
            throw new ApiError(422, 'templates_invalid', 'Template del blog da correggere: ' . implode('; ', $check['errors']));
        }

        $categories = json_decode($target->read("{$layout->data}content/categories.json") ?? '[]', true) ?: [];
        $posts = [];
        foreach ($target->list("{$layout->data}content/posts") as $path) {
            $post = json_decode($target->read($path) ?? '', true);
            if (is_array($post) && isset($post['id'], $post['slug'])) {
                $posts[] = $post;
            }
        }

        // repo senza articoli (es. sito fatto a mano appena collegato): quelli già scritti nel pannello restano
        $repoHasData = $posts || $categories;
        $hasData = $this->store->allPosts($site['id']) || $this->store->categories($site['id']);
        if ($repoHasData && $hasData && !$replace) {
            throw new ApiError(409, 'already_imported', 'Il sito ha già articoli: usa replace=true per sovrascriverli');
        }

        $config = ($site['config'] ?? []) + ['auto_publish' => true];
        $config['layout'] = $layout->name;
        if (is_array($siteJson)) {
            $blog = $siteJson['blog'] ?? [];
            unset($blog['warnings']);
            $config['site_name'] = $siteJson['site_name'] ?? $site['name'];
            $config['timezone'] = $siteJson['timezone'] ?? date_default_timezone_get();
            $config['blog'] = $blog;
        } else {
            // sito fatto a mano: blog sotto /blog/; le impostazioni già scelte nel pannello restano
            $config['site_name'] = $config['site_name'] ?? $site['name'];
            $config['blog'] = array_merge(Layout::STATIC_BLOG, $config['blog'] ?? []);
            if (Layout::staticBlogError($config['blog'])) {
                $config['blog'] = Layout::STATIC_BLOG;
            }
        }

        $this->db->transaction(function () use ($site, $replace, $repoHasData, $posts, $categories, $config, $single, $archive) {
            if ($replace && $repoHasData) {
                $this->db->run('DELETE FROM posts WHERE site_id = ?', [$site['id']]);
                $this->db->run('DELETE FROM categories WHERE site_id = ?', [$site['id']]);
            }
            foreach ($categories as $cat) {
                $this->store->saveCategory($site['id'], [
                    'slug' => $cat['slug'], 'name' => $cat['name'], 'description' => $cat['description'] ?? '', 'seo' => $cat['seo'] ?? [],
                ]);
            }
            $now = date('c');
            foreach ($posts as $p) {
                $this->store->savePost($site['id'], [
                    'id' => (string) $p['id'], 'slug' => $p['slug'], 'title' => $p['title'], 'excerpt' => $p['excerpt'] ?? '',
                    'content' => $p['content'] ?? '', 'status' => $p['status'] ?? 'published', 'date' => $p['date'],
                    'modified' => $p['modified'] ?? $p['date'], 'categories' => $p['categories'] ?? [], 'tags' => $p['tags'] ?? [],
                    'image' => $p['image'] ?? null, 'author' => $p['author'] ?? '', 'seo' => $p['seo'] ?? [],
                    'head_extra' => $p['head_extra'] ?? '', 'wp_id' => $p['wp_id'] ?? null, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $this->store->updateSite($site['id'], ['config' => $config, 'templates' => ['single' => $single, 'archive' => $archive]]);
        });

        // i file che il clonatore ha già generato diventano "gestiti": se un articolo sparisce, la sua pagina viene rimossa
        $site = $this->store->site($site['slug'], ['site_id' => null]);
        $this->store->updateSite($site['id'], ['manifest' => array_keys($this->build($site))]);

        return ['posts' => count($posts), 'categories' => count($categories), 'layout' => $layout->name, 'warnings' => $check['warnings']];
    }

    /** Ordine dei campi fisso: lo stesso file non deve cambiare tra una pubblicazione e l'altra. */
    public static function exportPost(array $post): array
    {
        $out = [];
        foreach (self::POST_FIELDS as $f) {
            $out[$f] = $post[$f] ?? null;
        }
        return $out;
    }

    private function log(array $site, string $actor, string $message, ?array $result, ?string $error = null): void
    {
        $this->db->insert('publish_log', [
            'site_id' => $site['id'], 'at' => date('c'), 'actor' => $actor, 'message' => $message,
            'revision' => $result['revision'] ?? null, 'written' => $result['written'] ?? 0,
            'deleted' => $result['deleted'] ?? 0, 'error' => $error,
        ]);
    }
}

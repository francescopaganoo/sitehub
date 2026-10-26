<?php
declare(strict_types=1);

namespace Panel\Publish;

use BlogRenderer;
use Panel\Db;
use Panel\Http\ApiError;
use Panel\Store;

/**
 * Dal database al repo del sito:
 *   public/…                    pagine del blog generate dai template
 *   content/posts/{slug}.json   copia dei dati (backup versionato, leggibile senza pannello)
 *   content/categories.json
 * I file generati in precedenza e non più prodotti (articolo cancellato, slug cambiato) vengono rimossi.
 */
final class Publisher
{
    private const JSON = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /** Campi di un articolo salvati nel repo (stesso formato prodotto dal clonatore). */
    private const POST_FIELDS = ['id', 'wp_id', 'slug', 'status', 'title', 'excerpt', 'content', 'date', 'modified',
        'categories', 'tags', 'image', 'author', 'seo', 'head_extra'];

    public function __construct(private Store $store, private Db $db)
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
            ['domain' => $site['domain'], 'site_name' => $site['config']['site_name'] ?? $site['name'], 'blog' => $site['config']['blog'] ?? []],
            $site['templates']
        );
    }

    /** @return array<string,string> path nel repo → contenuto */
    public function build(array $site): array
    {
        $posts = $this->store->allPosts($site['id']);
        $categories = $this->store->categories($site['id']);

        $files = [];
        foreach ($this->renderer($site)->render($posts, $categories)['files'] as $path => $html) {
            $files["public/$path"] = $html;
        }
        foreach ($posts as $post) {
            $files["content/posts/{$post['slug']}.json"] = json_encode(self::exportPost($post), self::JSON) . "\n";
        }
        $files['content/categories.json'] = json_encode($categories, self::JSON) . "\n";
        return $files;
    }

    /** @return array{revision:?string, written:int, deleted:int} */
    public function publish(array $site, string $actor, string $message): array
    {
        try {
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

    public function preview(array $site, array $post): string
    {
        return $this->renderer($site)->renderPost($post, $this->store->categories($site['id']));
    }

    /**
     * Importa nel pannello un sito prodotto dal clonatore (site.json, templates/, content/).
     *
     * @return array{posts:int, categories:int}
     */
    public function import(array $site, bool $replace): array
    {
        $target = self::target($site);
        $siteJson = json_decode($target->read('site.json') ?? '', true);
        $single = $target->read('templates/single.html');
        $archive = $target->read('templates/archive.html');
        if (!is_array($siteJson) || !$single || !$archive) {
            throw new ApiError(422, 'import_failed', 'Nel repo mancano site.json o i template del blog (templates/single.html, archive.html)');
        }

        $categories = json_decode($target->read('content/categories.json') ?? '[]', true) ?: [];
        $posts = [];
        foreach ($target->list('content/posts') as $path) {
            $post = json_decode($target->read($path) ?? '', true);
            if (is_array($post) && isset($post['id'], $post['slug'])) {
                $posts[] = $post;
            }
        }

        $hasData = $this->store->allPosts($site['id']) || $this->store->categories($site['id']);
        if ($hasData && !$replace) {
            throw new ApiError(409, 'already_imported', 'Il sito ha già articoli: usa replace=true per sovrascriverli');
        }

        $blog = $siteJson['blog'] ?? [];
        unset($blog['warnings']);
        $config = ($site['config'] ?? []) + ['auto_publish' => true];
        $config['site_name'] = $siteJson['site_name'] ?? $site['name'];
        $config['timezone'] = $siteJson['timezone'] ?? date_default_timezone_get();
        $config['blog'] = $blog;

        $this->db->transaction(function () use ($site, $replace, $posts, $categories, $config, $single, $archive) {
            if ($replace) {
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

        return ['posts' => count($posts), 'categories' => count($categories)];
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

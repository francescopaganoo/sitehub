<?php
declare(strict_types=1);

/**
 * Genera le pagine del blog (articoli, elenco, categorie, sitemap) da dati + template.
 * Funzione pura: non scrive su disco e non fa rete. La usano il clonatore e il pannello.
 *
 * Config blog (site.json → "blog"):
 *   permalink      "/{slug}/"  (segnaposti: {slug} {yyyy} {mm} {dd} {id})
 *   index_path     "/blog/" oppure null se il sito non ha una pagina elenco
 *   category_path  "/category/{slug}/"
 *   per_page       10
 *   static_pages   pagine non gestite dal blog (per la sitemap)
 *
 * Categorie: [{slug, name, description, seo: {title, description}}]
 */
final class BlogRenderer
{
    private const MONTHS = ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio',
        'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];

    private array $blog;
    private string $domain;
    private string $siteName;

    /** @param array{single:string, archive:string} $templates */
    public function __construct(array $site, private array $templates)
    {
        $this->blog = $site['blog'] + ['index_path' => null, 'per_page' => 10, 'static_pages' => [],
            'category_path' => '/category/{slug}/', 'index_title' => 'Blog'];
        $this->domain = rtrim($site['domain'], '/');
        $this->siteName = $site['site_name'] ?? '';
    }

    /**
     * @param array[] $posts      articoli (vedi content/posts/*.json)
     * @param array[] $categories [{slug, name, description}]
     * @return array{files: array<string,string>, paths: string[]} file da scrivere (path relativo a public/) e URL generati
     */
    public function render(array $posts, array $categories, ?int $now = null): array
    {
        $now ??= time();
        $posts = array_values(array_filter($posts,
            fn($p) => ($p['status'] ?? 'published') === 'published' && strtotime($p['date']) <= $now));
        usort($posts, fn($a, $b) => strcmp($b['date'], $a['date']));

        $catBySlug = array_column($categories, null, 'slug');
        $items = array_map(fn($p) => $this->postData($p, $catBySlug), $posts);

        $files = [];
        $sitemap = [];
        foreach ($items as $item) {
            $files[self::file($item['url'])] = Template::render($this->templates['single'], $item);
            $sitemap[$item['url']] = substr($item['modified_iso'], 0, 10);
        }

        if ($this->blog['index_path']) {
            $this->renderArchive($files, $sitemap, $items, $this->blog['index_path'], $this->blog['index_title'], null);
        }
        foreach ($categories as $cat) {
            $inCat = array_values(array_filter($items, fn($i) => in_array($cat['slug'], array_column($i['categories'], 'slug'), true)));
            if ($inCat) {
                $this->renderArchive($files, $sitemap, $inCat, $this->categoryUrl($cat['slug']), $cat['name'], $cat);
            }
        }

        $files['sitemap.xml'] = $this->sitemap($sitemap);
        return ['files' => $files, 'paths' => array_keys($sitemap)];
    }

    /** Un singolo articolo, anche bozza o programmato (anteprima nel pannello). */
    public function renderPost(array $post, array $categories): string
    {
        return Template::render($this->templates['single'], $this->postData($post, array_column($categories, null, 'slug')));
    }

    public function postUrl(array $post): string
    {
        $t = strtotime($post['date']);
        return strtr($this->blog['permalink'], [
            '{slug}' => $post['slug'], '{id}' => (string) $post['id'],
            '{yyyy}' => date('Y', $t), '{mm}' => date('m', $t), '{dd}' => date('d', $t),
        ]);
    }

    private function categoryUrl(string $slug): string
    {
        return str_replace('{slug}', $slug, $this->blog['category_path']);
    }

    private function postData(array $p, array $catBySlug): array
    {
        $url = $this->postUrl($p);
        $excerpt = trim($p['excerpt'] ?? '') ?: self::summary($p['content']);
        $image = !empty($p['image']['url']) ? $p['image'] + ['alt' => '', 'width' => '', 'height' => ''] : null;
        $cats = [];
        foreach ($p['categories'] ?? [] as $slug) {
            if (isset($catBySlug[$slug])) {
                $cats[] = ['slug' => $slug, 'name' => $catBySlug[$slug]['name'], 'url' => $this->categoryUrl($slug)];
            }
        }
        $seoTitle = ($p['seo']['title'] ?? '') ?: $p['title'] . ($this->siteName ? ' - ' . $this->siteName : '');
        $seoDesc = ($p['seo']['description'] ?? '') ?: mb_substr($excerpt, 0, 160);
        $modified = $p['modified'] ?? $p['date'];

        return [
            'id'              => $p['id'],
            'slug'            => $p['slug'],
            'title'           => $p['title'],
            'content'         => $p['content'],
            'excerpt'         => $excerpt,
            'url'             => $url,
            'canonical'       => $this->domain . $url,
            'date_iso'        => date('c', strtotime($p['date'])),
            'date_human'      => self::humanDate($p['date']),
            'modified_iso'    => date('c', strtotime($modified)),
            'image'           => $image,
            'og_image'        => $image ? $this->absolute($image['url']) : '',
            'categories'      => $cats,
            'author'          => $p['author'] ?? '',
            'seo_title'       => $seoTitle,
            'seo_description' => $seoDesc,
            'robots'          => !empty($p['seo']['noindex']) ? 'noindex, follow' : 'index, follow',
            'site_name'       => $this->siteName,
            'schema'          => self::json([
                '@context'         => 'https://schema.org',
                '@type'            => 'BlogPosting',
                'headline'         => $p['title'],
                'description'      => $seoDesc,
                'datePublished'    => date('c', strtotime($p['date'])),
                'dateModified'     => date('c', strtotime($modified)),
                'mainEntityOfPage' => $this->domain . $url,
                'image'            => $image ? $this->absolute($image['url']) : null,
                'author'           => ($p['author'] ?? '') ? ['@type' => 'Person', 'name' => $p['author']] : null,
                'publisher'        => ['@type' => 'Organization', 'name' => $this->siteName],
            ]),
        ];
    }

    private function renderArchive(array &$files, array &$sitemap, array $items, string $base, string $title, ?array $cat): void
    {
        $perPage = max(1, (int) $this->blog['per_page']);
        $pages = max(1, (int) ceil(count($items) / $perPage));

        for ($n = 1; $n <= $pages; $n++) {
            $url = $n === 1 ? $base : rtrim($base, '/') . "/page/$n/";
            $seoTitle = $n === 1 && ($cat['seo']['title'] ?? '')
                ? $cat['seo']['title']
                : $title . ($n > 1 ? " - Pagina $n" : '') . ($this->siteName ? ' - ' . $this->siteName : '');
            $data = [
                'archive_title'   => $title,
                'category'        => $cat ? ['name' => $cat['name'], 'slug' => $cat['slug']] : null,
                'description'     => $cat['description'] ?? '',
                'posts'           => array_slice($items, ($n - 1) * $perPage, $perPage),
                'empty_text'      => 'Nessun articolo pubblicato.',
                'pagination'      => $this->pagination($base, $n, $pages),
                'canonical'       => $this->domain . $url,
                'seo_title'       => $seoTitle,
                'seo_description' => ($cat['seo']['description'] ?? '') ?: (($cat['description'] ?? '') ?: $title),
                'og_image'        => '',
                'robots'          => 'index, follow',
                'site_name'       => $this->siteName,
                'schema'          => self::json(['@context' => 'https://schema.org', '@type' => 'CollectionPage',
                    'name' => $title, 'url' => $this->domain . $url]),
            ];
            $files[self::file($url)] = Template::render($this->templates['archive'], $data);
            $sitemap[$url] = $items ? substr($items[0]['modified_iso'], 0, 10) : null;
        }
    }

    /** Markup della paginazione di WordPress: i temi (ed Elementor) hanno già lo stile per queste classi. */
    private function pagination(string $base, int $current, int $total): string
    {
        if ($total < 2) {
            return '';
        }
        $url = fn(int $n) => $n === 1 ? $base : rtrim($base, '/') . "/page/$n/";
        $html = '<nav class="navigation pagination" aria-label="Paginazione articoli"><div class="nav-links">';
        if ($current > 1) {
            $html .= '<a class="prev page-numbers" href="' . $url($current - 1) . '">&laquo; Precedenti</a>';
        }
        for ($n = 1; $n <= $total; $n++) {
            $html .= $n === $current
                ? '<span aria-current="page" class="page-numbers current">' . $n . '</span>'
                : '<a class="page-numbers" href="' . $url($n) . '">' . $n . '</a>';
        }
        if ($current < $total) {
            $html .= '<a class="next page-numbers" href="' . $url($current + 1) . '">Successivi &raquo;</a>';
        }
        return $html . '</div></nav>';
    }

    private function sitemap(array $blogUrls): string
    {
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
        foreach ($this->blog['static_pages'] as $path) {
            $xml .= '  <url><loc>' . htmlspecialchars($this->domain . $path, ENT_XML1) . "</loc></url>\n";
        }
        foreach ($blogUrls as $path => $lastmod) {
            $xml .= '  <url><loc>' . htmlspecialchars($this->domain . $path, ENT_XML1) . '</loc>'
                . ($lastmod ? "<lastmod>$lastmod</lastmod>" : '') . "</url>\n";
        }
        return $xml . "</urlset>\n";
    }

    private function absolute(string $url): string
    {
        return str_starts_with($url, '/') ? $this->domain . $url : $url;
    }

    public static function humanDate(string $date): string
    {
        $t = strtotime($date);
        return date('j', $t) . ' ' . self::MONTHS[(int) date('n', $t) - 1] . ' ' . date('Y', $t);
    }

    /** Estratto automatico: prime ~30 parole del testo. */
    public static function summary(string $html, int $words = 30): string
    {
        $text = trim(preg_replace('~\s+~u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)));
        $parts = explode(' ', $text);
        return count($parts) > $words ? implode(' ', array_slice($parts, 0, $words)) . '…' : $text;
    }

    /** "/blog/" → "blog/index.html" */
    public static function file(string $url): string
    {
        $path = ltrim(rawurldecode($url), '/');
        return preg_match('~\.html?$~', $path) ? $path : ($path === '' ? '' : rtrim($path, '/') . '/') . 'index.html';
    }

    /** JSON-LD sicuro dentro <script>: niente "</script>" e senza chiavi nulle. */
    private static function json(array $data): string
    {
        return json_encode(array_filter($data, fn($v) => $v !== null),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
    }
}

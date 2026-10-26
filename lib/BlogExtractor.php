<?php
declare(strict_types=1);

/**
 * Estrae il blog da WordPress: articoli e categorie via REST API, template ricavati dalle pagine clonate.
 *
 * I template si costruiscono "per dati": conoscendo titolo, testo, immagine ed estratto di un articolo,
 * si cerca nella pagina l'elemento che li contiene e lo si sostituisce con un segnaposto.
 * Così funziona con qualunque tema (Hello, Astra, Elementor Theme Builder…) senza selettori dedicati;
 * i selettori noti servono solo da ripiego.
 */
final class BlogExtractor
{
    /** Zone della pagina che non fanno parte del contenuto (menu, header, footer, banner). */
    private const CHROME = 'header, footer, nav, [data-elementor-type="header"], [data-elementor-type="footer"], [data-elementor-type="popup"]';

    private const CONTENT_FALLBACK = '.elementor-widget-theme-post-content > .elementor-widget-container, .elementor-widget-theme-post-content, '
        . '.entry-content, .page-content, .post-content, .single-content, article .content';

    private const ITEM = 'article, .elementor-post, .e-loop-item, .post, li';

    private array $warnings = [];

    /**
     * @param Closure(string):?string $internalPath URL assoluto → path interno (o null)
     * @param Closure(string):string  $rewrite      riscrive gli URL del sito in root-relative e accoda le risorse
     * @param Closure(string):void    $log
     */
    public function __construct(
        private string $origin,
        private string $public,
        private Closure $internalPath,
        private Closure $rewrite,
        private Closure $log,
    ) {
    }

    /**
     * @param string[] $crawledPages path delle pagine clonate
     * @return ?array{posts:array, categories:array, templates:array, config:array, warnings:array}
     */
    public function extract(array $crawledPages): ?array
    {
        $rawPosts = $this->fetchAll('posts?_embed=1');
        if ($rawPosts === null) {
            ($this->log)('Blog: REST API non disponibile, blog non estratto');
            return null;
        }
        if (!$rawPosts) {
            ($this->log)('Blog: nessun articolo');
            return null;
        }
        $rawCats = $this->fetchAll('categories') ?? [];
        $info = $this->siteInfo();
        if ($info['timezone']) {
            date_default_timezone_set($info['timezone']);
        }

        $categories = [];
        $catSlugById = [];
        foreach ($rawCats as $c) {
            $catSlugById[$c['id']] = $c['slug'];
            if ($c['count'] > 0) {
                $categories[] = ['slug' => $c['slug'], 'name' => html_entity_decode($c['name'], ENT_QUOTES | ENT_HTML5),
                    'description' => trim(strip_tags($c['description'] ?? '')),
                    'seo' => ['title' => self::decode($c['yoast_head_json']['title'] ?? ''),
                        'description' => self::decode($c['yoast_head_json']['description'] ?? '')]];
            }
        }

        $posts = [];
        $paths = [];
        foreach ($rawPosts as $p) {
            $post = $this->convertPost($p, $catSlugById);
            $posts[] = $post;
            $paths[$post['slug']] = ($this->internalPath)($p['link']);
        }

        $config = [
            'permalink'     => $this->permalinkPattern($rawPosts[0], $paths[$rawPosts[0]['slug']]),
            'category_path' => $this->categoryPattern($rawCats),
            'index_path'    => $this->findIndexPage($crawledPages),
            'index_title'   => 'Blog',
            'per_page'      => 10,
        ];

        // CSS specifico dei singoli articoli costruiti con Elementor
        foreach ($posts as &$post) {
            $html = $this->readPage($paths[$post['slug']]);
            $post['head_extra'] = $html ? $this->postHeadExtra($html, (int) $post['wp_id']) : '';
        }
        unset($post);

        $single = $this->buildSingleTemplate($posts, $paths);
        $archive = $this->buildArchiveTemplate($posts, $categories, $config, $crawledPages);
        if ($single === null || $archive === null) {
            return null;
        }

        ($this->log)(sprintf('Blog: %d articoli, %d categorie, permalink %s', count($posts), count($categories), $config['permalink']));
        return ['posts' => $posts, 'categories' => $categories, 'templates' => ['single' => $single, 'archive' => $archive],
            'config' => $config, 'site_name' => $info['name'], 'timezone' => $info['timezone'], 'warnings' => $this->warnings];
    }

    // ---------------------------------------------------------------- dati

    private function fetchAll(string $endpoint): ?array
    {
        $all = [];
        for ($page = 1; $page <= 100; $page++) {
            $sep = str_contains($endpoint, '?') ? '&' : '?';
            $res = Http::get("{$this->origin}/wp-json/wp/v2/{$endpoint}{$sep}per_page=100&page=$page");
            if ($res['status'] === 400 && $page > 1) {
                break; // oltre l'ultima pagina
            }
            $batch = $res['status'] === 200 ? json_decode($res['body'], true) : null;
            if (!is_array($batch)) {
                return $page === 1 ? null : $all;
            }
            $all = array_merge($all, $batch);
            if (count($batch) < 100) {
                break;
            }
        }
        return $all;
    }

    /**
     * Nome del sito e fuso orario di WordPress: le date degli articoli sono in ora locale del sito,
     * senza fuso orario dichiarato, quindi vanno interpretate col suo.
     */
    private function siteInfo(): array
    {
        $info = json_decode(Http::get($this->origin . '/wp-json/', 0)['body'], true) ?: [];
        $tz = $info['timezone_string'] ?? '';
        return ['name' => self::decode($info['name'] ?? ''), 'timezone' => in_array($tz, timezone_identifiers_list(), true) ? $tz : null];
    }

    private function convertPost(array $p, array $catSlugById): array
    {
        $emb = $p['_embedded'] ?? [];
        $media = $emb['wp:featuredmedia'][0] ?? null;
        $image = null;
        if ($media && !empty($media['source_url'])) {
            $image = [
                'url'    => ($this->rewrite)($media['source_url']),
                'alt'    => $media['alt_text'] ?? '',
                'width'  => $media['media_details']['width'] ?? null,
                'height' => $media['media_details']['height'] ?? null,
            ];
        }
        $tags = [];
        foreach ($emb['wp:term'] ?? [] as $group) {
            foreach ($group as $term) {
                if (($term['taxonomy'] ?? '') === 'post_tag') {
                    $tags[] = html_entity_decode($term['name'], ENT_QUOTES | ENT_HTML5);
                }
            }
        }
        $yoast = $p['yoast_head_json'] ?? [];

        return [
            'id'         => (string) $p['id'],
            'wp_id'      => $p['id'],
            'slug'       => $p['slug'],
            'status'     => 'published',
            'title'      => self::decode($p['title']['rendered']),
            'excerpt'    => self::decode(strip_tags($p['excerpt']['rendered'] ?? '')),
            'content'    => trim(($this->rewrite)($p['content']['rendered'])),
            'date'       => $p['date'],
            'modified'   => $p['modified'],
            'categories' => array_values(array_filter(array_map(fn($id) => $catSlugById[$id] ?? null, $p['categories'] ?? []))),
            'tags'       => $tags,
            'image'      => $image,
            'author'     => $emb['author'][0]['name'] ?? '',
            'seo'        => [
                'title'       => isset($yoast['title']) ? self::decode($yoast['title']) : '',
                'description' => isset($yoast['description']) ? self::decode($yoast['description']) : '',
                'noindex'     => ($yoast['robots']['index'] ?? 'index') === 'noindex',
            ],
        ];
    }

    private function permalinkPattern(array $p, ?string $path): string
    {
        if ($path === null || !str_contains($path, $p['slug'])) {
            $this->warnings[] = 'Permalink "semplici" (?p=): i nuovi URL useranno /{slug}/';
            return '/{slug}/';
        }
        $t = strtotime($p['date']);
        $pattern = str_replace('/' . $p['slug'] . '/', '/{slug}/', rtrim($path, '/') . '/');
        foreach (['{yyyy}' => 'Y', '{mm}' => 'm', '{dd}' => 'd'] as $token => $fmt) {
            $pattern = preg_replace('~/' . date($fmt, $t) . '/~', "/$token/", $pattern, 1);
        }
        return str_replace('{id}', (string) $p['id'], $pattern);
    }

    private function categoryPattern(array $rawCats): string
    {
        foreach ($rawCats as $c) {
            $path = ($this->internalPath)($c['link'] ?? '');
            if ($path && str_contains($path, '/' . $c['slug'] . '/')) {
                return str_replace('/' . $c['slug'] . '/', '/{slug}/', $path);
            }
        }
        return '/category/{slug}/';
    }

    /** La pagina articoli di WordPress ha la classe "blog" sul body. */
    private function findIndexPage(array $crawledPages): ?string
    {
        foreach ($crawledPages as $path) {
            $html = $this->readPage($path);
            if ($html && preg_match('~<body[^>]*class="[^"]*\bblog\b~', $html)) {
                return $path;
            }
        }
        return null;
    }

    private function postHeadExtra(string $html, int $wpId): string
    {
        $doc = Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
        $out = '';
        foreach ($doc->querySelectorAll("link[id=\"elementor-post-$wpId-css\"], style[id=\"elementor-post-$wpId\"]") as $el) {
            $out .= $doc->saveHtml($el);
        }
        return $out;
    }

    // ---------------------------------------------------------------- template articolo

    private function buildSingleTemplate(array $posts, array $paths): ?string
    {
        // meglio un articolo con immagine in evidenza: il template copre più casi
        usort($posts, fn($a, $b) => (int) empty($a['image']) <=> (int) empty($b['image']));
        foreach ($posts as $post) {
            $html = $this->readPage($paths[$post['slug']]);
            if ($html) {
                return $this->makeSingle($html, $post);
            }
        }
        $this->warnings[] = 'Nessuna pagina articolo clonata: template articolo non creato';
        return null;
    }

    private function makeSingle(string $html, array $post): string
    {
        $doc = Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
        $this->templateHead($doc, 'article');

        foreach ($doc->querySelectorAll('[id^="elementor-post-' . $post['wp_id'] . '"]') as $el) {
            $el->remove();
        }
        $doc->head->appendChild($doc->createTextNode('{{{head_extra}}}'));

        $title = $this->findText($doc, 'h1', $post['title'])
            ?? $this->findText($doc, 'h2, h3, .entry-title, .elementor-heading-title', $post['title']);
        if ($title) {
            $title->textContent = '{{title}}';
        } else {
            $this->warnings[] = 'Template articolo: titolo non trovato';
        }

        $content = $this->findContent($doc, $post['content']);
        if ($content) {
            while ($content->firstChild) {
                $content->firstChild->remove();
            }
            $content->appendChild($doc->createTextNode('{{{content}}}'));
        } else {
            $this->warnings[] = 'Template articolo: contenuto non trovato';
        }

        $this->templateImage($doc, $doc->body, $post['image']['url'] ?? null);
        $this->templateDates($doc->body);
        $this->templateCategoryLinks($doc, $doc->body);

        foreach ($doc->querySelectorAll('.elementor-widget-post-navigation, .post-navigation, nav.navigation.post-navigation') as $nav) {
            $nav->remove();
            $this->warnings[] = 'Template articolo: navigazione articolo precedente/successivo rimossa';
        }

        $this->templateIds($doc, (string) $post['wp_id']);
        return $doc->saveHtml();
    }

    private function findContent(Dom\HTMLDocument $doc, string $contentHtml): ?Dom\Element
    {
        $target = self::squash(strip_tags($contentHtml));
        if ($target !== '') {
            $match = $this->deepest($doc->body, 'div, section, article, main', fn($el) => self::squash($el->textContent) === $target);
            if ($match) {
                return $match;
            }
        }
        foreach ($doc->querySelectorAll(self::CONTENT_FALLBACK) as $el) {
            if (!$this->inChrome($el)) {
                return $el;
            }
        }
        return null;
    }

    // ---------------------------------------------------------------- template elenco

    private function buildArchiveTemplate(array $posts, array $categories, array &$config, array $crawledPages): ?string
    {
        // fonte: la pagina articoli se esiste, altrimenti la categoria con più articoli
        $candidates = [];
        if ($config['index_path']) {
            $candidates[] = [$config['index_path'], null];
        }
        $byCount = $categories;
        usort($byCount, fn($a, $b) => count(array_filter($posts, fn($p) => in_array($b['slug'], $p['categories'], true)))
            <=> count(array_filter($posts, fn($p) => in_array($a['slug'], $p['categories'], true))));
        foreach ($byCount as $cat) {
            $candidates[] = [str_replace('{slug}', $cat['slug'], $config['category_path']), $cat];
        }

        foreach ($candidates as [$path, $cat]) {
            $html = $this->readPage($path);
            if (!$html) {
                continue;
            }
            $onPage = $cat ? array_filter($posts, fn($p) => in_array($cat['slug'], $p['categories'], true)) : $posts;
            usort($onPage, fn($a, $b) => strcmp($b['date'], $a['date']));
            $tpl = $this->makeArchive($html, array_values($onPage), $cat, $config);
            if ($tpl) {
                return $tpl;
            }
        }
        $this->warnings[] = 'Nessuna pagina elenco/categoria utilizzabile: template elenco non creato';
        return null;
    }

    private function makeArchive(string $html, array $posts, ?array $cat, array &$config): ?string
    {
        $doc = Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
        $renderer = new BlogRenderer(['domain' => '', 'blog' => $config], ['single' => '', 'archive' => '']);

        // il primo articolo presente in pagina fa da modello per la "card"
        $item = null;
        $post = null;
        foreach ($posts as $p) {
            foreach ($doc->querySelectorAll('a[href="' . $renderer->postUrl($p) . '"]') as $a) {
                if (!$this->inChrome($a)) {
                    $item = $a->closest(self::ITEM) ?? $a->parentElement;
                    $post = $p;
                    break 2;
                }
            }
        }
        if (!$item) {
            return null;
        }

        $container = $item->parentElement;
        $siblings = 0;
        for ($sib = $container->firstElementChild; $sib; $sib = $next) {
            $next = $sib->nextElementSibling;
            if ($sib !== $item && $sib->localName === $item->localName && self::firstClass($sib) === self::firstClass($item)) {
                $sib->remove();
                $siblings++;
            }
        }
        $pagination = $doc->querySelector('.elementor-pagination, nav.pagination, .navigation.pagination, .pagination, .nav-links');
        if ($pagination) {
            $config['per_page'] = $siblings + 1;
        }

        $this->templateHead($doc, 'website');
        $this->templateArchiveHeading($doc, $cat);

        // segnaposti dentro la card
        foreach ($item->querySelectorAll('a[href="' . $renderer->postUrl($post) . '"]') as $a) {
            $a->setAttribute('href', '{{url}}');
        }
        $title = $this->findText($item, 'h1, h2, h3, h4, a, .entry-title, .elementor-post__title', $post['title']);
        if ($title) {
            $title->textContent = '{{title}}';
        }
        $excerpt = self::norm($post['excerpt']);
        $ex = $this->deepest($item, 'p, div', function ($el) use ($excerpt) {
            $t = rtrim(self::norm($el->textContent), " .…[]");
            return strlen($t) > 20 && ($t === rtrim($excerpt, " .…[]") || str_starts_with($excerpt, $t));
        });
        if ($ex) {
            $ex->textContent = '{{excerpt}}';
        }
        $this->templateImage($doc, $item, $post['image']['url'] ?? null);
        $this->templateDates($item);
        $this->templateCategoryLinks($doc, $item);

        $item->before($doc->createTextNode('{{#posts}}'));
        $item->after($doc->createTextNode('{{/posts}}{{^posts}}{{empty_text}}{{/posts}}'));

        if ($pagination) {
            $pagination->replaceWith($doc->createTextNode('{{{pagination}}}'));
        } else {
            $container->after($doc->createTextNode('{{{pagination}}}'));
        }

        return $doc->saveHtml();
    }

    /** "Categoria: <span>News</span>" → prefisso solo per le categorie, nome variabile. */
    private function templateArchiveHeading(Dom\HTMLDocument $doc, ?array $cat): void
    {
        $name = $cat['name'] ?? 'Blog';
        $h = $this->deepest($doc->body, 'h1, .page-title, .entry-title, .elementor-heading-title',
            fn($el) => str_contains(self::norm($el->textContent), $name));
        if (!$h) {
            $this->warnings[] = 'Template elenco: titolo pagina non trovato';
            return;
        }
        foreach (self::textNodes($h) as $node) {
            if (str_contains($node->textContent, $name)) {
                $node->textContent = str_replace($name, '{{archive_title}}', $node->textContent);
            } elseif (trim($node->textContent) !== '') {
                $node->textContent = '{{#category}}' . $node->textContent . '{{/category}}';
            }
        }
        foreach ($doc->querySelectorAll('.taxonomy-description, .archive-description') as $d) {
            $d->textContent = '{{description}}';
        }
    }

    // ---------------------------------------------------------------- parti comuni

    private function templateHead(Dom\HTMLDocument $doc, string $ogType): void
    {
        $head = $doc->head;
        $meta = function (string $attr, string $key, string $value, bool $create) use ($doc, $head): void {
            $el = $doc->querySelector("meta[$attr=\"$key\"]");
            if (!$el && $create) {
                $el = $doc->createElement('meta');
                $el->setAttribute($attr, $key);
                $head->appendChild($el);
            }
            $el?->setAttribute('content', $value);
        };

        $title = $doc->querySelector('title') ?? $head->appendChild($doc->createElement('title'));
        $title->textContent = '{{seo_title}}';
        $meta('name', 'description', '{{seo_description}}', true);
        $meta('name', 'robots', '{{robots}}', false);
        $meta('property', 'og:title', '{{seo_title}}', false);
        $meta('property', 'og:description', '{{seo_description}}', false);
        $meta('property', 'og:url', '{{canonical}}', false);
        $meta('property', 'og:type', $ogType, false);
        $meta('property', 'og:image', '{{og_image}}', false);
        $meta('name', 'twitter:title', '{{seo_title}}', false);
        $meta('name', 'twitter:description', '{{seo_description}}', false);
        $meta('name', 'twitter:image', '{{og_image}}', false);
        $meta('property', 'article:published_time', '{{date_iso}}', false);
        $meta('property', 'article:modified_time', '{{modified_iso}}', false);
        $meta('name', 'author', '{{author}}', false);

        $canonical = $doc->querySelector('link[rel="canonical"]');
        if (!$canonical) {
            $canonical = $doc->createElement('link');
            $canonical->setAttribute('rel', 'canonical');
            $head->appendChild($canonical);
        }
        $canonical->setAttribute('href', '{{canonical}}');

        foreach ($doc->querySelectorAll('meta[property^="og:image:"], meta[name^="twitter:label"], meta[name^="twitter:data"], '
            . 'link[rel="prev"], link[rel="next"]') as $el) {
            $el->remove();
        }

        $schema = $doc->querySelector('script.yoast-schema-graph, script[type="application/ld+json"]');
        if (!$schema) {
            $schema = $doc->createElement('script');
            $schema->setAttribute('type', 'application/ld+json');
            $head->appendChild($schema);
        }
        $schema->textContent = '{{{schema}}}';
    }

    /** Immagine in evidenza: riconosciuta dal nome file (senza il suffisso -300x200 delle miniature). */
    private function templateImage(Dom\HTMLDocument $doc, Dom\ParentNode $scope, ?string $imageUrl): void
    {
        if (!$imageUrl) {
            return;
        }
        $stem = preg_replace('~(-\d+x\d+)?\.\w+$~', '', basename(strtok($imageUrl, '?')));
        foreach ($scope->querySelectorAll('img') as $img) {
            $refs = ($img->getAttribute('src') ?? '') . ' ' . ($img->getAttribute('srcset') ?? '') . ' ' . ($img->getAttribute('data-src') ?? '');
            if ($this->inChrome($img) || !preg_match('~/' . preg_quote($stem, '~') . '(-\d+x\d+)?\.\w+~', $refs)) {
                continue;
            }
            $img->setAttribute('src', '{{image.url}}');
            $img->setAttribute('alt', '{{image.alt}}');
            foreach (['width' => '{{image.width}}', 'height' => '{{image.height}}'] as $attr => $val) {
                if ($img->hasAttribute($attr)) {
                    $img->setAttribute($attr, $val);
                }
            }
            foreach (['srcset', 'sizes', 'data-src', 'data-srcset'] as $attr) {
                $img->removeAttribute($attr);
            }
            $wrap = $img->closest('.elementor-widget-theme-post-featured-image, .post-thumbnail, .elementor-post__thumbnail__link, figure') ?? $img;
            if (!$scope->contains($wrap)) {
                $wrap = $img;
            }
            $wrap->before($doc->createTextNode('{{#image}}'));
            $wrap->after($doc->createTextNode('{{/image}}'));
            return;
        }
    }

    private function templateDates(Dom\ParentNode $scope): void
    {
        foreach ($scope->querySelectorAll('time') as $t) {
            if ($this->inChrome($t)) {
                continue;
            }
            $modified = str_contains($t->getAttribute('class') ?? '', 'updated');
            $t->setAttribute('datetime', $modified ? '{{modified_iso}}' : '{{date_iso}}');
            $t->textContent = $modified ? '' : '{{date_human}}';
        }
        foreach ($scope->querySelectorAll('.elementor-post-info__item--type-date, .elementor-post-date') as $d) {
            $d->textContent = '{{date_human}}';
        }
    }

    /** Link alle categorie: il primo resta come modello, ripetuto per ogni categoria dell'articolo. */
    private function templateCategoryLinks(Dom\HTMLDocument $doc, Dom\ParentNode $scope): void
    {
        $groups = [];
        foreach ($scope->querySelectorAll('a[rel~="category"], a[href*="/category/"]') as $a) {
            if (!$this->inChrome($a) && $a->parentElement) {
                $groups[spl_object_id($a->parentElement)][] = $a;
            }
        }
        foreach ($groups as $links) {
            $first = array_shift($links);
            foreach ($links as $a) {
                $prev = $a->previousSibling;
                if ($prev instanceof Dom\Text && trim($prev->textContent, " ,|·\n\t") === '') {
                    $prev->remove();
                }
                $a->remove();
            }
            $first->setAttribute('href', '{{url}}');
            $first->textContent = '{{name}}';
            $first->before($doc->createTextNode('{{#categories}}'));
            $first->after($doc->createTextNode('{{/categories}}'));
        }
    }

    /** postid-208 / post-208 nelle classi → id dell'articolo renderizzato. */
    private function templateIds(Dom\HTMLDocument $doc, string $wpId): void
    {
        foreach ($doc->querySelectorAll('[class*="post-' . $wpId . '"], [class*="postid-' . $wpId . '"]') as $el) {
            $el->setAttribute('class', preg_replace('~\b(postid|post)-' . $wpId . '\b~', '$1-{{id}}', $el->getAttribute('class')));
        }
    }

    // ---------------------------------------------------------------- utilità DOM

    private function findText(Dom\ParentNode $scope, string $selector, string $text): ?Dom\Element
    {
        $want = self::norm($text);
        return $this->deepest($scope, $selector, fn($el) => self::norm($el->textContent) === $want);
    }

    /** L'ultimo elemento in ordine di documento che soddisfa il criterio è il più interno. */
    private function deepest(Dom\ParentNode $scope, string $selector, callable $pred): ?Dom\Element
    {
        $match = null;
        foreach ($scope->querySelectorAll($selector) as $el) {
            if (!$this->inChrome($el) && $pred($el)) {
                $match = $el;
            }
        }
        return $match;
    }

    private function inChrome(Dom\Element $el): bool
    {
        return $el->closest(self::CHROME) !== null;
    }

    private function readPage(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }
        $file = $this->public . '/' . BlogRenderer::file($path);
        return is_file($file) ? file_get_contents($file) : null;
    }

    /** @return Dom\Text[] */
    private static function textNodes(Dom\Node $node): array
    {
        $out = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof Dom\Text) {
                $out[] = $child;
            } else {
                array_push($out, ...self::textNodes($child));
            }
        }
        return $out;
    }

    private static function firstClass(Dom\Element $el): string
    {
        return explode(' ', trim($el->getAttribute('class') ?? ''))[0];
    }

    private static function decode(string $s): string
    {
        return trim(html_entity_decode($s, ENT_QUOTES | ENT_HTML5));
    }

    private static function norm(string $s): string
    {
        return trim(preg_replace('~\s+~u', ' ', html_entity_decode($s, ENT_QUOTES | ENT_HTML5)));
    }

    /** Testo senza spazi: confronto robusto tra HTML dell'API e DOM della pagina. */
    private static function squash(string $s): string
    {
        return preg_replace('~\s+~u', '', html_entity_decode($s, ENT_QUOTES | ENT_HTML5));
    }
}

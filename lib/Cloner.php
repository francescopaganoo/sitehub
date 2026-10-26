<?php
declare(strict_types=1);

/**
 * Clona un sito WordPress in HTML statico.
 *
 * Strategia: gli URL del sito restano identici (stessi path, /wp-content/... compreso),
 * diventano solo root-relative. Così SEO, srcset e config JS di Elementor restano validi
 * senza dover rimappare nulla. I meta assoluti (canonical, og:, JSON-LD) restano assoluti.
 */
final class Cloner
{
    /** Attributi che possono contenere URL di risorse. */
    private const URL_ATTRS = ['src', 'href', 'srcset', 'data-src', 'data-srcset', 'data-lazy-src',
        'data-lazy-srcset', 'data-bg', 'data-background', 'poster', 'imagesrcset'];

    /** Path WordPress che non vanno clonati come pagine. */
    private const DEFAULT_EXCLUDE = ['~^/wp-(admin|login|json|cron)~', '~^/xmlrpc\.php~', '~/feed/?$~',
        '~^/comments/~', '~^/author/~', '~^/cdn-cgi/~', '~^/\?~'];

    /** Elementi WordPress inutili nel sito statico. */
    private const REMOVE_SELECTORS = [
        'link[rel="EditURI"]', 'link[rel="wlwmanifest"]', 'link[rel="shortlink"]', 'link[rel="pingback"]',
        'link[rel="https://api.w.org/"]', 'link[type="application/json+oembed"]', 'link[type="text/xml+oembed"]',
        'link[rel="alternate"][type="application/json"]', 'link[rel="alternate"][type="application/rss+xml"]',
        'link[rel="dns-prefetch"][href*="s.w.org"]', 'link[rel="preconnect"][href*="fonts.gstatic.com"]',
        'meta[name="generator"]', 'script[src*="/cdn-cgi/"]', 'script[id^="wp-emoji"]',
        'script[id^="wpascript"]', 'link[id^="wpa-css"]', 'script[id^="comment-reply"]',
        '.comment-respond',
    ];

    private string $origin;
    private string $domain;
    private array $hosts;
    private string $siteRe;
    private string $public;
    private array $exclude;
    private Consent $consent;

    private array $pageQueue = [];
    private array $seenPages = [];
    private array $assetQueue = [];
    private array $seenAssets = [];
    private array $fontCss = [];
    /** path in minuscolo → path reale, per le collisioni su filesystem case-insensitive (macOS) */
    private array $lowerPaths = [];
    /** path originale → path rinominato */
    private array $aliases = [];
    private array $textFiles = [];
    private ?array $blog = null;
    private array $structure = [];
    private string $siteName = '';
    private ?string $timezone = null;

    private array $pages = [];
    private array $forms = [];
    private array $redirects = [];
    private array $external = [];
    private array $errors = [];
    private int $bytes = 0;

    /**
     * @param array{url:string, out:string, slug:string, domain?:string, embed:?string, exclude?:string[], local_fonts?:bool} $cfg
     */
    public function __construct(private array $cfg)
    {
        $u = parse_url(rtrim($cfg['url'], '/'));
        $this->origin = $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
        $this->domain = rtrim($cfg['domain'] ?? $this->origin, '/');

        $bare = preg_replace('~^www\.~', '', $u['host']);
        $this->hosts = [$bare, 'www.' . $bare];
        $h = implode('|', array_map(fn($x) => preg_quote($x, '~'), $this->hosts));
        // URL assoluti del sito, anche in forma JSON-escaped (https:\/\/host\/path)
        $this->siteRe = '~(?:https?:)?(?:\\\\?/){2}(?:' . $h . ')(?![\w.-])((?:\\\\/|[^\s"\'<>()\\\\,{}|^`\[\]])*)~i';

        $this->public = rtrim($cfg['out'], '/') . '/public';
        $this->consent = new Consent($bare);
        $this->exclude = array_merge(self::DEFAULT_EXCLUDE, $cfg['exclude'] ?? []);
    }

    public function run(): array
    {
        $started = microtime(true);
        $this->log("Clono {$this->origin} → {$this->public}");

        $this->enqueuePage('/');
        foreach ($this->sitemapPaths() as $p) {
            $this->enqueuePage($p);
        }

        while ($this->pageQueue) {
            $this->processPage(array_shift($this->pageQueue));
        }
        $this->processPage('/__ssg-not-found-' . bin2hex(random_bytes(4)) . '/', true);

        // prima delle risorse: le immagini degli articoli vanno accodate
        $blog = (new BlogExtractor($this->origin, $this->public, fn($u) => $this->internalPath($u),
            fn($s) => $this->rewriteText($s), fn($m) => $this->log($m)))->extract($this->pages);

        while ($this->assetQueue) {
            $this->processAsset(array_shift($this->assetQueue));
        }

        $this->applyAliases();
        $this->writeSupportFiles();
        if ($blog) {
            $this->writeBlog($blog);
        }
        // per ultima: lavora sui file finali di pagine, template e articoli
        $this->structure = (new Restructure(dirname($this->public), fn($m) => $this->log($m)))->run();
        $report = $this->report(microtime(true) - $started);
        file_put_contents(dirname($this->public) . '/site.json',
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        return $report;
    }

    // ---------------------------------------------------------------- crawl

    private function sitemapPaths(): array
    {
        foreach (['/sitemap_index.xml', '/wp-sitemap.xml', '/sitemap.xml'] as $candidate) {
            $res = Http::get($this->origin . $candidate, 0);
            if ($res['status'] === 200 && str_contains($res['body'], '<loc>')) {
                $this->log("Sitemap: $candidate");
                return $this->readSitemap($res['body'], 0);
            }
        }
        $this->log('Nessuna sitemap trovata, uso solo i link interni');
        return [];
    }

    private function readSitemap(string $xml, int $depth): array
    {
        preg_match_all('~<loc>\s*(.*?)\s*</loc>~s', $xml, $m);
        $paths = [];
        foreach ($m[1] as $loc) {
            $loc = html_entity_decode($loc, ENT_QUOTES | ENT_XML1);
            $path = $this->internalPath($loc);
            if ($path === null) {
                continue;
            }
            if (Url::extension($path) === 'xml') {
                if ($depth < 2) {
                    $paths = array_merge($paths, $this->readSitemap(Http::get($loc, 0)['body'], $depth + 1));
                }
            } else {
                $paths[] = $path;
            }
        }
        return $paths;
    }

    private function enqueuePage(string $path): void
    {
        $path = strtok($path, '#') ?: '/';
        if (isset($this->seenPages[$path]) || $this->isExcluded($path)) {
            return;
        }
        $this->seenPages[$path] = true;
        $this->pageQueue[] = $path;
    }

    private function enqueueAsset(string $path): void
    {
        $path = strtok($path, '#') ?: '';
        $key = strtok($path, '?') ?: '';
        if ($key === '' || isset($this->seenAssets[$key]) || preg_match('~^/(cdn-cgi|_ssg)/~', $key)) {
            return;
        }
        $this->seenAssets[$key] = true;
        $this->assetQueue[] = $path;

        // es. ALService-1.webp e alservice-1.webp: su macOS sarebbero lo stesso file
        $lower = strtolower(rawurldecode($key));
        if (isset($this->lowerPaths[$lower])) {
            $info = pathinfo($key);
            $this->aliases[$key] = $info['dirname'] . '/' . $info['filename'] . '-' . substr(md5($key), 0, 6)
                . (isset($info['extension']) ? '.' . $info['extension'] : '');
            $this->log("  rinomino $key → {$this->aliases[$key]} (collide con {$this->lowerPaths[$lower]})");
        } else {
            $this->lowerPaths[$lower] = $key;
        }
    }

    /** Un path trovato in un link: è una risorsa (pdf, immagine…) o una pagina? */
    private function enqueueLink(string $path): void
    {
        $this->isAssetPath($path) ? $this->enqueueAsset($path) : $this->enqueuePage($path);
    }

    private function isAssetPath(string $path): bool
    {
        $ext = Url::extension($path);
        return $ext !== '' && !in_array($ext, ['php', 'html', 'htm'], true);
    }

    private function isExcluded(string $path): bool
    {
        if (str_contains($path, '?')) {
            return true;
        }
        foreach ($this->exclude as $re) {
            if (preg_match($re, $path)) {
                return true;
            }
        }
        return false;
    }

    /** Path (con query) se l'URL appartiene al sito, altrimenti null. */
    private function internalPath(string $url): ?string
    {
        $u = parse_url($url);
        if (!isset($u['host']) || !in_array(strtolower($u['host']), $this->hosts, true)) {
            return null;
        }
        if (isset($u['scheme']) && !in_array($u['scheme'], ['http', 'https'], true)) {
            return null;
        }
        return ($u['path'] ?? '/') . (isset($u['query']) ? '?' . $u['query'] : '');
    }

    // ---------------------------------------------------------------- pagine

    private function processPage(string $path, bool $is404 = false): void
    {
        $url = $this->origin . $path;
        $res = Http::get($url);

        if ($is404) {
            if ($res['status'] !== 404 || !str_contains($res['type'], 'html')) {
                $this->log("Pagina 404 non disponibile (status {$res['status']})");
                return;
            }
        } else {
            if ($res['status'] !== 200) {
                $this->error($path, "HTTP {$res['status']} {$res['error']}");
                return;
            }
            $final = $this->internalPath($res['url']);
            if ($final !== null && $final !== $path) {
                $this->redirects[$path] = $final;
                $this->log("  redirect $path → $final");
                $this->enqueuePage($final);
                return;
            }
            if (!str_contains($res['type'], 'html')) {
                $this->enqueueAsset($path);
                return;
            }
        }

        $html = $this->transformHtml($res['body'], $url, $is404 ? '/404' : $path);
        $file = $is404 ? '/404.html' : $this->pageFile($path);
        $this->write($file, $html);
        if (!$is404) {
            $this->pages[] = $path;
        }
        $this->log(($is404 ? '404 ' : 'PAG ') . $path);
    }

    private function pageFile(string $path): string
    {
        $path = rawurldecode($path);
        if (preg_match('~\.html?$~', $path)) {
            return $path;
        }
        return rtrim($path, '/') . '/index.html';
    }

    private function transformHtml(string $html, string $pageUrl, string $pagePath): string
    {
        $doc = Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');

        $this->decodeCloudflareEmails($doc);
        $this->consent->removeOldCmp($doc, $pagePath);
        foreach (self::REMOVE_SELECTORS as $sel) {
            foreach ($doc->querySelectorAll($sel) as $el) {
                $el->remove();
            }
        }
        foreach ($doc->querySelectorAll('script:not([src])') as $s) {
            // emoji di WordPress, script anti-bot che Cloudflare inietta nelle pagine
            if (str_contains($s->textContent, '_wpemojiSettings') || str_contains($s->textContent, '/cdn-cgi/challenge-platform')) {
                $s->remove();
            }
        }
        // senza form dei commenti, una sezione commenti vuota non ha senso
        foreach ($doc->querySelectorAll('#comments') as $c) {
            if (!$c->querySelector('.comment-list, .commentlist, .comment')) {
                $c->remove();
            }
        }

        foreach ($doc->querySelectorAll('a[href]') as $a) {
            $path = $this->internalPath(Url::resolve($pageUrl, $a->getAttribute('href')));
            if ($path !== null) {
                $this->enqueueLink($path);
            }
        }

        if ($this->cfg['local_fonts'] ?? true) {
            $this->localizeGoogleFonts($doc);
        }

        $this->convertForms($doc, $pagePath);

        foreach (iterator_to_array($doc->getElementsByTagName('*')) as $el) {
            $keepAbsolute = $this->keepsAbsolute($el);
            $mode = $keepAbsolute ? 'absolute' : 'relative';

            foreach (iterator_to_array($el->attributes) as $attr) {
                $name = $attr->name;
                $value = $attr->value;
                if (in_array($name, self::URL_ATTRS, true)) {
                    $this->noteRelativeRefs($name, $value, $pageUrl);
                }
                $new = $this->rewriteText($value, $mode);
                if ($new !== $value) {
                    $el->setAttribute($name, $new);
                }
            }

            if ($el->localName === 'script' || $el->localName === 'style') {
                $text = $el->textContent;
                $new = $this->rewriteText($text, $mode);
                if ($new !== $text) {
                    $el->textContent = $new;
                }
            }
        }

        $this->consent->block($doc);

        // su tutte le pagine anche senza servizi da bloccare o form: i moduli si attivano dal pannello
        // senza dover rigenerare l'HTML
        foreach (array_filter(['/_ssg/consent.js', $this->cfg['embed']]) as $src) {
            $script = $doc->createElement('script');
            $script->setAttribute('src', $src);
            $script->setAttribute('defer', '');
            $doc->body->appendChild($script);
        }

        return $doc->saveHtml();
    }

    /** Elementi i cui URL devono restare assoluti (sul dominio finale). */
    private function keepsAbsolute(Dom\Element $el): bool
    {
        return match ($el->localName) {
            'meta'   => (bool) preg_match('~^(og:|twitter:)~', $el->getAttribute('property') ?? $el->getAttribute('name') ?? ''),
            'link'   => in_array($el->getAttribute('rel'), ['canonical', 'alternate'], true),
            'script' => $el->getAttribute('type') === 'application/ld+json',
            default  => false,
        };
    }

    /**
     * Riscrive gli URL assoluti del sito (anche JSON-escaped) in root-relative,
     * o sul dominio finale in modalità 'absolute', e accoda le risorse trovate.
     */
    private function rewriteText(string $text, string $mode = 'relative'): string
    {
        return preg_replace_callback($this->siteRe, function (array $m) use ($mode): string {
            $escaped = str_contains($m[0], '\\/');
            $path = str_replace('\\/', '/', $m[1]);
            if ($path === '' || $path[0] !== '/') {
                $path = '/' . $path;
            }
            if ($this->isAssetPath($path)) {
                $this->enqueueAsset(html_entity_decode($path));
            }
            $out = $mode === 'absolute' ? $this->domain . $path : $path;
            return $escaped ? str_replace('/', '\\/', $out) : $out;
        }, $text) ?? $text;
    }

    /** URL relativi o root-relative negli attributi (src, srcset, href…). */
    private function noteRelativeRefs(string $attr, string $value, string $baseUrl): void
    {
        $refs = str_contains($attr, 'srcset')
            ? array_map(fn($part) => preg_split('~\s+~', trim($part))[0], explode(',', $value))
            : [$value];

        foreach ($refs as $ref) {
            if ($ref === '' || preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|#)~i', $ref)) {
                continue;
            }
            $path = $this->internalPath(Url::resolve($baseUrl, $ref));
            if ($path !== null && $this->isAssetPath($path)) {
                $this->enqueueAsset($path);
            }
        }
    }

    /** Cloudflare offusca le email: senza il suo JS resterebbe "[email protected]". */
    private function decodeCloudflareEmails(Dom\HTMLDocument $doc): void
    {
        $decode = static function (string $hex): string {
            $key = hexdec(substr($hex, 0, 2));
            $out = '';
            for ($i = 2; $i < strlen($hex); $i += 2) {
                $out .= chr(hexdec(substr($hex, $i, 2)) ^ $key);
            }
            return $out;
        };

        foreach ($doc->querySelectorAll('[data-cfemail]') as $el) {
            $el->replaceWith($doc->createTextNode($decode($el->getAttribute('data-cfemail'))));
        }
        foreach ($doc->querySelectorAll('a[href*="/cdn-cgi/l/email-protection#"]') as $a) {
            $hex = substr($a->getAttribute('href'), strpos($a->getAttribute('href'), '#') + 1);
            $a->setAttribute('href', 'mailto:' . $decode($hex));
        }
    }

    /** Scarica i Google Fonts in locale (GDPR) e sostituisce i <link>. */
    private function localizeGoogleFonts(Dom\HTMLDocument $doc): void
    {
        foreach ($doc->querySelectorAll('link[href*="fonts.googleapis.com"]') as $link) {
            $href = Url::resolve($this->origin . '/', $link->getAttribute('href'));
            if (!isset($this->fontCss[$href])) {
                $this->fontCss[$href] = $this->downloadGoogleFont($href);
            }
            if ($this->fontCss[$href] !== null) {
                $link->setAttribute('href', $this->fontCss[$href]);
            }
        }
    }

    private function downloadGoogleFont(string $cssUrl): ?string
    {
        $res = Http::get($cssUrl);
        if ($res['status'] !== 200) {
            $this->error($cssUrl, "Google Font non scaricabile (HTTP {$res['status']})");
            return null;
        }

        $css = preg_replace_callback('~url\((https://fonts\.gstatic\.com/s/([^)]+))\)~', function (array $m): string {
            $local = '/_ssg/fonts/' . $m[2];
            if (!is_file($this->public . $local)) {
                $font = Http::get($m[1]);
                if ($font['status'] === 200) {
                    $this->write($local, $font['body']);
                } else {
                    $this->error($m[1], "font HTTP {$font['status']}");
                }
            }
            return "url($local)";
        }, $res['body']);

        $local = '/_ssg/fonts/' . substr(md5($cssUrl), 0, 10) . '.css';
        $this->write($local, $css);
        $this->log("FONT $cssUrl");
        return $local;
    }

    /**
     * Collega i form (Elementor, CF7) al modulo form del pannello: basta marcarli con data-ssg-form.
     * Etichette, honeypot e blocco degli handler AJAX di WordPress li gestisce embed.js nel browser.
     */
    private function convertForms(Dom\HTMLDocument $doc, string $pagePath): void
    {
        foreach ($doc->querySelectorAll('form.elementor-form, form.wpcf7-form') as $form) {
            // lo stesso form (es. nel footer) ha lo stesso id su tutte le pagine
            $wpId = $form->querySelector('input[name="form_id"], input[name="_wpcf7"]')?->getAttribute('value') ?? spl_object_id($form);
            if (!isset($this->forms[$wpId])) {
                $name = trim($form->getAttribute('name') ?? '');
                $key = preg_match('~^(new form|)$~i', $name) ? 'contatti' : trim(preg_replace('~[^a-z0-9]+~', '-', strtolower($name)), '-');
                $taken = array_column($this->forms, 'key');
                for ($n = 2, $base = $key; in_array($key, $taken, true); $n++) {
                    $key = "$base-$n";
                }
                $this->forms[$wpId] = ['key' => $key, 'name' => $name ?: 'Contatti', 'pages' => []];
            }
            $this->forms[$wpId]['pages'][] = $pagePath;

            $form->setAttribute('data-ssg-form', $this->forms[$wpId]['key']);
            $form->setAttribute('method', 'post');
            if ($this->submitUrl()) {
                $form->setAttribute('action', $this->submitUrl()); // invio anche senza JavaScript
            } else {
                $form->removeAttribute('action');
            }
        }
    }

    /** URL di invio dei form ricavato dallo script embed (…/e/v1/pk_x.js → …/e/v1/pk_x/submit). */
    private function submitUrl(): ?string
    {
        return $this->cfg['embed'] ? preg_replace('~\.js$~', '/submit', $this->cfg['embed']) : null;
    }

    // ---------------------------------------------------------------- risorse

    private function processAsset(string $path): void
    {
        $res = Http::get($this->origin . $path);
        if ($res['status'] !== 200) {
            $this->error($path, "HTTP {$res['status']} {$res['error']}");
            return;
        }

        $clean = strtok($path, '?') ?: $path;
        $body = $res['body'];
        $ext = Url::extension($clean);

        if ($ext === 'css') {
            $body = $this->processCss($body, $this->origin . $clean);
        } elseif ($ext === 'js') {
            $body = $this->rewriteText($body);
            if (str_contains(basename($clean), 'runtime')) {
                $this->discoverWebpackChunks($body, $clean);
            }
            // file che Elementor carica da solo (dialog, share-link, CSS della lightbox…)
            if (preg_match('~/assets/js/[^/]+\.js$~', $clean)) {
                foreach (Restructure::loaderPaths($body) as $p) {
                    $this->enqueueAsset(dirname($clean, 2) . '/' . $p);
                }
            }
        }

        $this->write(rawurldecode($this->aliases[$clean] ?? $clean), $body);
    }

    /** Aggiorna i riferimenti ai file rinominati in HTML, CSS e JS già scritti. */
    private function applyAliases(): void
    {
        if (!$this->aliases) {
            return;
        }
        foreach (array_keys($this->textFiles) as $file) {
            $content = file_get_contents($file);
            $new = $this->aliased($content);
            if ($new !== $content) {
                file_put_contents($file, $new);
            }
        }
    }

    private function processCss(string $css, string $cssUrl): string
    {
        $css = $this->rewriteText($css);

        preg_match_all('~url\(\s*([\'"]?)([^\'")]+)\1\s*\)|@import\s+([\'"])([^\'"]+)\3~i', $css, $m, PREG_SET_ORDER);
        foreach ($m as $match) {
            $ref = trim($match[2] !== '' ? $match[2] : ($match[4] ?? ''));
            if ($ref === '' || preg_match('~^(data:|#)~i', $ref)) {
                continue;
            }
            $abs = Url::resolve($cssUrl, $ref);
            $path = $this->internalPath($abs);
            if ($path !== null) {
                $this->enqueueAsset($path);
            } else {
                $this->noteExternal($abs);
            }
        }
        return $css;
    }

    /** Elementor carica moduli JS on-demand: i nomi dei chunk sono scritti nel runtime webpack. */
    private function discoverWebpackChunks(string $js, string $runtimePath): void
    {
        preg_match_all('~["\']([\w.-]+\.(?:bundle\.)?min\.(?:js|css))["\']~', $js, $m);
        $dir = dirname($runtimePath);
        foreach (array_unique($m[1]) as $chunk) {
            $this->enqueueAsset($dir . '/' . $chunk);
        }
    }

    private function aliased(string $text): string
    {
        $map = [];
        foreach ($this->aliases as $from => $to) {
            $map[$from] = $to;
            $map[str_replace('/', '\\/', $from)] = str_replace('/', '\\/', $to);
        }
        return $map ? strtr($text, $map) : $text;
    }

    // ---------------------------------------------------------------- blog

    /**
     * Salva dati e template del blog fuori da public/ (sono la "sorgente"),
     * poi rigenera le pagine del blog col renderer: se i template sono sbagliati si vede subito.
     */
    private function writeBlog(array $blog): void
    {
        $root = dirname($this->public);
        $json = fn($v) => json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

        foreach (['content/posts', 'templates'] as $dir) {
            exec('rm -rf ' . escapeshellarg("$root/$dir"));
            mkdir("$root/$dir", 0775, true);
        }
        foreach ($blog['posts'] as $i => $post) {
            $blog['posts'][$i] = json_decode($this->aliased(json_encode($post)), true);
            file_put_contents("$root/content/posts/{$post['slug']}.json", $json($blog['posts'][$i]));
        }
        file_put_contents("$root/content/categories.json", $json($blog['categories']));
        foreach ($blog['templates'] as $name => $tpl) {
            $blog['templates'][$name] = $this->aliased($tpl);
            file_put_contents("$root/templates/$name.html", $blog['templates'][$name]);
        }

        $site = ['domain' => $this->domain, 'site_name' => $blog['site_name'], 'blog' => $blog['config'] + ['static_pages' => []]];
        $managed = (new BlogRenderer($site, $blog['templates']))->render($blog['posts'], $blog['categories'])['paths'];
        $site['blog']['static_pages'] = array_values(array_filter($this->pages,
            fn($p) => !in_array($p, $managed, true) && !preg_match('~/page/\d+/$~', $p)));

        $result = (new BlogRenderer($site, $blog['templates']))->render($blog['posts'], $blog['categories']);
        foreach ($result['files'] as $file => $content) {
            $this->write('/' . $file, $content);
        }
        $this->log('Blog: rigenerate ' . count($result['files']) . ' pagine dai template');

        $this->siteName = $blog['site_name'];
        $this->timezone = $blog['timezone'] ?? date_default_timezone_get();
        $this->blog = $site['blog'] + ['warnings' => $blog['warnings']];
    }

    // ---------------------------------------------------------------- output

    private function writeSupportFiles(): void
    {

        $privacy = array_values(array_filter($this->pages, fn($p) => str_contains($p, 'privacy')))[0] ?? null;
        $config = $this->consent->config($this->cfg['slug'], $this->public, $privacy);
        $this->write('/_ssg/consent.js', 'window.SSG_CONSENT = '
            . json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ";\n\n"
            . file_get_contents(__DIR__ . '/../assets/consent.js'));

        $urls = '';
        foreach ($this->pages as $p) {
            $urls .= '  <url><loc>' . htmlspecialchars($this->domain . $p, ENT_XML1) . "</loc></url>\n";
        }
        $this->write('/sitemap.xml', "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n$urls</urlset>\n");
        $this->write('/robots.txt', "User-agent: *\nAllow: /\n\nSitemap: {$this->domain}/sitemap.xml\n");
    }

    private function report(float $seconds): array
    {
        sort($this->pages);
        return [
            'slug'          => $this->cfg['slug'],
            'origin'        => $this->origin,
            'domain'        => $this->domain,
            'site_name'     => $this->siteName,
            'timezone'      => $this->timezone,
            'embed'         => $this->cfg['embed'],
            'cloned_at'     => date('c'),
            'stats'         => [
                'pages'   => count($this->pages),
                'assets'  => count($this->seenAssets),
                'mb'      => round($this->bytes / 1048576, 1),
                'seconds' => round($seconds),
            ] + $this->structure,
            'pages'         => $this->pages,
            'forms'         => array_values($this->forms),
            'cookies'       => $this->consent->report(),
            'blog'          => $this->blog,
            // vecchi percorsi WordPress → nuovi: servono per i redirect sul server (link esterni, Google Immagini)
            'moved_paths'   => Restructure::PREFIXES,
            'redirects'     => $this->redirects,
            'external'      => array_keys($this->external),
            'errors'        => $this->errors,
        ];
    }

    private function write(string $relPath, string $content): void
    {
        if (preg_match('~(^|/)\.\.(/|$)~', $relPath)) {
            $this->error($relPath, 'path non valido');
            return;
        }
        $file = $this->public . $relPath;
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }
        file_put_contents($file, $content);
        $this->bytes += strlen($content);
        if (in_array(Url::extension($file), ['html', 'css', 'js'], true)) {
            $this->textFiles[$file] = true;
        }
    }

    private function noteExternal(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        if ($host) {
            $this->external[$host] = true;
        }
    }

    private function error(string $what, string $msg): void
    {
        $this->errors[] = "$what: $msg";
        $this->log("ERR $what: $msg");
    }

    private function log(string $msg): void
    {
        fwrite(STDOUT, $msg . "\n");
    }
}

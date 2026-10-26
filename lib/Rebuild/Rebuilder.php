<?php
declare(strict_types=1);

namespace Rebuild;

use BlogRenderer;
use Closure;
use Dom;

/**
 * Modalità "ricostruzione": dal clone fedele (sites/x) a un sito pulito (sites/x-pulito).
 * Legge le pagine già clonate (nessuna rete, tranne i glifi delle icone) e riscrive:
 *   pagine HTML semantiche, un solo style.css, site.js minimo, solo font e immagini usati.
 * Struttura del repo identica al clone (public/, templates/, content/, site.json): il pannello la gestisce allo stesso modo.
 */
final class Rebuilder
{
    private Css $css;
    private Theme $theme;
    private Extractor $extractor;
    private Renderer $renderer;
    private Icons $icons;
    private array $site;
    private string $header = '';
    private string $footer = '';
    private string $floating = '';
    private string $scripts = '';
    private string $lang = 'it';
    private bool $stickyHeader = false;

    /** @param ?string $embed URL dello script del pannello (…/e/v1/pk_x.js): sostituisce quello del clone */
    public function __construct(private string $src, private string $out, private Closure $log, private ?string $embed = null)
    {
        $this->src = rtrim($src, '/');
        $this->out = rtrim($out, '/');
    }

    public function run(): array
    {
        $this->site = json_decode(file_get_contents("{$this->src}/site.json"), true);
        $cssFile = "{$this->src}/public/assets/css/style.css";
        $this->css = Css::parse(is_file($cssFile) ? file_get_contents($cssFile) : '');
        $this->theme = Theme::fromCss($this->css);
        $this->icons = new Icons($this->site['origin'], sys_get_temp_dir() . '/ssg-icons');
        $this->extractor = new Extractor($this->css, $this->icons);
        $this->renderer = new Renderer($this->theme);
        if ($this->embed) {
            $this->renderer->formAction = preg_replace('~\.js$~', '/submit', $this->embed);
        }

        if (is_dir("{$this->out}/public")) {
            // si svuota senza cancellare la cartella: un server di anteprima che la serve resta valido
            exec('find ' . escapeshellarg("{$this->out}/public") . ' -mindepth 1 -delete; rm -rf ' . escapeshellarg("{$this->out}/templates"));
        }

        $home = $this->load('/');
        $this->lang = $home->documentElement->getAttribute('lang') ?: 'it';
        $this->chrome($home);

        $pages = $this->site['blog']['static_pages'] ?? $this->site['pages'];
        foreach ($pages as $path) {
            $doc = $path === '/' ? $home : $this->load($path);
            if ($doc) {
                $this->renderer->resetForPage();
                $this->write('public/' . BlogRenderer::file($path), $this->layout($this->head($doc), $this->main($doc)));
                ($this->log)("PAG $path");
            }
        }
        if ($doc404 = $this->loadFile('404.html')) {
            $this->write('public/404.html', $this->layout($this->head($doc404), $this->main($doc404)));
        }

        $blogFiles = !empty($this->site['blog']) ? $this->blog() : [];

        $this->writeAssets();
        $this->copyStatic();
        $stats = $this->stats();
        $warnings = array_values(array_unique([...$this->extractor->warnings,
            ...array_map(fn($i) => "icona non trovata: $i", array_keys($this->icons->missing))]));

        $site = $this->site;
        $site['mode'] = 'ricostruzione';
        $site['rebuild'] = ['stats' => $stats, 'warnings' => $warnings, 'blog_pages' => count($blogFiles)];
        unset($site['moved_paths']);
        $site['embed'] = $this->embed ?? $site['embed'] ?? null;
        $this->write('site.json', json_encode($site, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        \DeployFiles::write($this->out, $site);

        ($this->log)(sprintf('Ricostruito: %d file, %.1f MB (%d avvisi)', $stats['files'], $stats['mb'], count($warnings)));
        foreach ($warnings as $w) {
            ($this->log)("  ! $w");
        }
        return $site['rebuild'];
    }

    // ---------------------------------------------------------------- pagine

    /** Header, footer ed elementi fissi (WhatsApp) presi dalla home: uguali su tutte le pagine. */
    private function chrome(Dom\HTMLDocument $home): void
    {
        $header = $home->querySelector('[data-elementor-type="header"]');
        $footer = $home->querySelector('[data-elementor-type="footer"]');
        $this->header = $header ? $this->renderer->document($this->extractor->children($header), 'header') : $this->themePart($home, 'header');
        $this->footer = $footer ? $this->renderer->document($this->extractor->children($footer), 'footer') : $this->themePart($home, 'footer');

        // con un elemento "sticky" nell'header, resta fisso l'intero header (il comportamento visivo è lo stesso)
        if (str_contains($this->header, 'is-sticky')) {
            $this->stickyHeader = true;
            $this->header = str_replace(' is-sticky', '', $this->header);
        }

        foreach ($home->querySelectorAll('script:not([src])') as $s) {
            if (preg_match('~ht_ctc_chat_var\s*=\s*(\{.*?\});~s', $s->textContent, $m)) {
                $number = preg_replace('~\D~', '', json_decode($m[1], true)['number'] ?? '');
                if ($number !== '') {
                    $this->floating = '<a class="wa-float" href="https://wa.me/' . $number . '" target="_blank" rel="noopener" aria-label="Scrivici su WhatsApp">'
                        . $this->icons->svg('fab fa-whatsapp') . '</a>';
                }
            }
        }

        // script dei moduli del pannello (form, …): quello indicato, altrimenti quello del clone
        $embeds = $this->embed ? [$this->embed] : array_map(fn($s) => $s->getAttribute('src'), iterator_to_array($home->querySelectorAll('script[src*="/e/v1/"]')));
        foreach ($embeds as $src) {
            $this->scripts .= '<script src="' . htmlspecialchars($src) . '" defer></script>' . "\n";
        }
    }

    /** Header/footer di un tema senza Elementor: si tiene l'HTML del tema ripulito. */
    private function themePart(Dom\HTMLDocument $doc, string $tag): string
    {
        $el = $doc->querySelector("body > $tag, #site-$tag, .site-$tag");
        if (!$el) {
            return '';
        }
        $this->extractor->warnings[] = "$tag del tema (non Elementor) copiato com'era";
        return Extractor::clean($el);
    }

    private function main(Dom\HTMLDocument $doc): string
    {
        $content = $doc->querySelector('[data-elementor-type="wp-page"], [data-elementor-type="wp-post"], [data-elementor-type="single-page"], '
            . '[data-elementor-type="single-post"], [data-elementor-type="single"], [data-elementor-type="error-404"]');
        if ($content) {
            return $this->renderer->document($this->extractor->children($content), 'main');
        }
        // pagina senza Elementor (privacy, cookie policy…): titolo + testo
        $title = $doc->querySelector('main .entry-title, main h1, .page-header h1');
        $body = $doc->querySelector('main .page-content, main .entry-content, .page-content, .entry-content');
        $html = $body ? Extractor::clean($body) : ($doc->querySelector('main') ? Extractor::clean($doc->querySelector('main')) : '');
        return '<article class="prose">' . ($title ? '<h1>' . trim($title->innerHTML) . '</h1>' : '') . $html . '</article>';
    }

    /** Meta SEO, social, dati strutturati e icone della pagina originale. */
    private function head(Dom\HTMLDocument $doc): string
    {
        $keep = 'meta[charset], meta[name="viewport"], title, meta[name="description"], meta[name="robots"], link[rel="canonical"], '
            . 'meta[property^="og:"], meta[property^="article:"], meta[name^="twitter:"], script[type="application/ld+json"], '
            . 'link[rel~="icon"], link[rel="apple-touch-icon"], meta[name="msapplication-TileImage"], meta[name$="-site-verification"]';
        $head = '';
        foreach ($doc->querySelectorAll($keep) as $el) {
            $head .= $doc->saveHtml($el) . "\n";
        }
        if (!str_contains($head, 'viewport')) {
            $head = '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n" . $head;
        }
        return $head . '<link rel="stylesheet" href="/assets/style.css?v={{css_version}}">';
    }

    private function layout(string $head, string $main): string
    {
        $scripts = $this->scripts . '<script src="/assets/consent.js" defer></script>' . "\n"
            . ($this->renderer->usesMenu || $this->renderer->usesLightbox ? '<script src="/assets/site.js" defer></script>' . "\n" : '');
        return "<!doctype html>\n<html lang=\"{$this->lang}\">\n<head>\n<meta charset=\"utf-8\">\n"
            . preg_replace('~<meta charset[^>]*>\n?~i', '', $head) . "\n</head>\n<body>\n"
            . "<a class=\"sr-only\" href=\"#content\">Vai al contenuto</a>\n"
            . '<header class="site-header' . ($this->stickyHeader ? ' is-sticky' : '') . "\">\n{$this->header}</header>\n"
            . "<main id=\"content\">\n$main</main>\n"
            . "<footer class=\"site-footer\">\n{$this->footer}</footer>\n"
            . $this->floating . "\n" . $scripts . "</body>\n</html>\n";
    }

    // ---------------------------------------------------------------- blog

    /** Template del blog con la stessa grafica del sito; le pagine del blog vengono rigenerate. @return string[] file */
    private function blog(): array
    {
        $single = $this->loadFile('../templates/single.html');
        $archive = $this->loadFile('../templates/archive.html');
        if (!$single || !$archive) {
            return [];
        }
        $templates = [
            'single' => $this->layout($this->head($single), <<<HTML
<article class="prose post">
{{#image}}<img class="post-img" src="{{image.url}}" alt="{{image.alt}}" width="{{image.width}}" height="{{image.height}}">{{/image}}
<h1>{{title}}</h1>
<p class="post-meta"><time datetime="{{date_iso}}">{{date_human}}</time>{{#categories}} · <a href="{{url}}">{{name}}</a>{{/categories}}</p>
{{{content}}}
</article>
HTML),
            'archive' => $this->layout($this->head($archive), <<<HTML
<section class="prose archivio">
<h1>{{#category}}Categoria: {{/category}}{{archive_title}}</h1>
{{#posts}}<article class="post-card">
{{#image}}<a href="{{url}}"><img src="{{image.url}}" alt="{{image.alt}}" loading="lazy"></a>{{/image}}
<h2><a href="{{url}}">{{title}}</a></h2>
<p class="post-meta"><time datetime="{{date_iso}}">{{date_human}}</time></p>
<p>{{excerpt}}</p>
</article>{{/posts}}
{{^posts}}<p>{{empty_text}}</p>{{/posts}}
{{{pagination}}}
</section>
HTML),
        ];
        foreach ($templates as $name => $tpl) {
            $this->write("templates/$name.html", $tpl);
        }

        // contenuti: stessi JSON del clone
        $posts = [];
        foreach (glob("{$this->src}/content/posts/*.json") ?: [] as $f) {
            $this->write('content/posts/' . basename($f), file_get_contents($f));
            $posts[] = json_decode(file_get_contents($f), true);
        }
        $categories = json_decode(@file_get_contents("{$this->src}/content/categories.json") ?: '[]', true);
        $this->write('content/categories.json', json_encode($categories, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

        $result = (new BlogRenderer($this->site, $templates))->render($posts, $categories);
        foreach ($result['files'] as $file => $html) {
            $this->write("public/$file", $html);
        }
        ($this->log)('Blog: ' . count($result['files']) . ' file');
        return array_keys($result['files']);
    }

    // ---------------------------------------------------------------- risorse

    private function writeAssets(): void
    {
        [$fontCss, $fontFiles] = $this->fonts();
        $css = "/* Stile del sito – generato dalla ricostruzione, modificabile liberamente */\n\n"
            . $this->theme->rootCss() . "\n" . $fontCss . "\n" . $this->theme->baseCss() . "\n"
            . file_get_contents(__DIR__ . '/../../assets/rebuild/base.css')
            . "\n/* ==========================================================================\n   Sezioni del sito\n   ========================================================================== */\n\n"
            . $this->renderer->css();
        $this->write('public/assets/style.css', $css);

        // versione nel link del CSS: ogni modifica invalida la cache del browser
        $version = substr(md5($css), 0, 8);
        foreach ($this->files("{$this->out}/public") as $f) {
            if (str_ends_with($f, '.html')) {
                file_put_contents($f, str_replace('{{css_version}}', $version, file_get_contents($f)));
            }
        }
        foreach (['single', 'archive'] as $t) {
            $f = "{$this->out}/templates/$t.html";
            if (is_file($f)) {
                file_put_contents($f, str_replace('{{css_version}}', $version, file_get_contents($f)));
            }
        }

        if ($this->renderer->usesMenu || $this->renderer->usesLightbox) {
            $this->write('public/assets/site.js', file_get_contents(__DIR__ . '/../../assets/rebuild/site.js'));
        }
        foreach ($fontFiles as $from => $to) {
            $this->write("public$to", file_get_contents("{$this->src}/public$from"));
        }
        $consent = "{$this->src}/public/assets/consent.js";
        if (is_file($consent)) {
            $this->write('public/assets/consent.js', file_get_contents($consent));
        }
    }

    /**
     * Solo i font del tema, solo caratteri latini (italiano compreso): niente cirillico, greco, vietnamita.
     *
     * @return array{0:string, 1:array<string,string>} CSS @font-face, file da copiare (origine → destinazione)
     */
    private function fonts(): array
    {
        $cssFile = "{$this->src}/public/assets/css/style.css";
        if (!is_file($cssFile)) {
            return ['', []];
        }
        $families = array_map('strtolower', array_keys($this->theme->usedFonts()));
        $out = '';
        $files = [];
        preg_match_all('~@font-face\s*\{[^}]*\}~i', file_get_contents($cssFile), $m);
        foreach ($m[0] as $block) {
            if (!preg_match('~font-family\s*:\s*[\'"]?([^;\'"]+)~i', $block, $fam) || !in_array(strtolower(trim($fam[1])), $families, true)) {
                continue;
            }
            if (preg_match('~unicode-range\s*:\s*([^;}]+)~i', $block, $range) && !str_contains(strtoupper($range[1]), 'U+0000-00FF')) {
                continue;
            }
            $block = preg_replace_callback('~url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)~', function ($u) use (&$files) {
                $path = strtok($u[1], '?#');
                $to = '/assets/fonts/' . basename($path);
                $files[$path] = $to;
                return "url($to)";
            }, $block);
            $out .= (str_contains($block, 'font-display') ? $block : str_replace('{', '{font-display:swap;', $block)) . "\n";
        }
        return [$out, $files];
    }

    /** Immagini usate da pagine, CSS, template e articoli; robots.txt e sitemap. */
    private function copyStatic(): void
    {
        $text = '';
        foreach ([...$this->files("{$this->out}/public"), ...$this->files("{$this->out}/templates"), ...$this->files("{$this->out}/content")] as $f) {
            if (preg_match('~\.(html|css|json|js)$~', $f)) {
                $text .= str_replace('\\/', '/', file_get_contents($f));
            }
        }
        preg_match_all('~/media/[^\s"\'()<>,?#\\\\]+~', $text, $m);
        foreach (array_unique($m[0]) as $path) {
            $from = "{$this->src}/public" . rawurldecode($path);
            if (is_file($from)) {
                $this->write('public' . rawurldecode($path), file_get_contents($from));
            }
        }
        foreach (['robots.txt', 'sitemap.xml'] as $f) {
            if (is_file("{$this->src}/public/$f") && !is_file("{$this->out}/public/$f")) {
                $this->write("public/$f", file_get_contents("{$this->src}/public/$f"));
            }
        }
    }

    // ---------------------------------------------------------------- utilità

    private function load(string $path): ?Dom\HTMLDocument
    {
        return $this->loadFile(BlogRenderer::file($path));
    }

    private function loadFile(string $rel): ?Dom\HTMLDocument
    {
        $file = "{$this->src}/public/" . $rel;
        return is_file($file) ? Dom\HTMLDocument::createFromString(file_get_contents($file), LIBXML_NOERROR, 'UTF-8') : null;
    }

    private function write(string $rel, string $content): void
    {
        $file = "{$this->out}/$rel";
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }
        file_put_contents($file, $content);
    }

    private function files(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile()) {
                $out[] = $f->getPathname();
            }
        }
        return $out;
    }

    private function stats(): array
    {
        $files = $this->files("{$this->out}/public");
        return ['files' => count($files), 'mb' => round(array_sum(array_map('filesize', $files)) / 1048576, 2)];
    }
}

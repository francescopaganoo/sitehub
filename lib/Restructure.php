<?php
declare(strict_types=1);

/**
 * Ultima fase del clone: da "copia di WordPress" a sito statico pulito.
 *  1. percorsi WordPress → struttura pulita (/wp-content/uploads/ → /media/, plugin → /assets/…)
 *     mantenendo le sottocartelle interne: Elementor carica file on-demand partendo dalla sua cartella base
 *  2. font: solo .woff2 (gli altri formati servivano a browser che non esistono più)
 *  3. JS di Elementor: via i moduli dell'editor e quelli dei widget non presenti nel sito
 *  4. tutti i CSS delle pagine in un unico /assets/css/style.css
 *  5. via i ?ver= di WordPress e i file che nessuna pagina usa
 *
 * Lavora su public/, templates/ e content/ (articoli del blog): i percorsi devono restare coerenti ovunque.
 */
final class Restructure
{
    /** Ordine importante: i prefissi più specifici prima. */
    public const PREFIXES = [
        '/wp-content/plugins/elementor/assets/'     => '/assets/elementor/',
        '/wp-content/plugins/elementor-pro/assets/' => '/assets/elementor-pro/',
        '/wp-content/plugins/pro-elements/assets/'  => '/assets/elementor-pro/',
        '/wp-content/uploads/'                      => '/media/',
        '/wp-content/plugins/'                      => '/assets/plugins/',
        '/wp-content/themes/'                       => '/assets/themes/',
        '/wp-includes/'                             => '/assets/wp/',
        '/_ssg/'                                    => '/assets/',
    ];

    /**
     * Moduli JS di Elementor legati a widget: modulo (regex sul nome) → widget che lo richiedono (regex sul tipo).
     * Un modulo che non è in elenco si tiene sempre: meglio un file in più che un widget rotto.
     */
    private const WIDGET_CHUNKS = [
        '~^(accordion|alert|counter|progress|tabs|toggle|video|image-carousel|text-editor|wp-audio|nested-tabs|nested-accordion|text-path|'
            . 'animated-headline|media-carousel|countdown|hotspot|lottie|code-highlight|video-playlist|table-of-contents|paypal-button|'
            . 'stripe-button|progress-tracker|share-buttons|slides|blockquote|flip-box|price-list|price-table|reviews|gallery|'
            . 'testimonial-carousel|call-to-action|form|login|nav-menu|portfolio|nested-carousel|off-canvas|search-form|search|'
            . 'taxonomy-filter)$~' => null, // null = stesso nome del widget
        '~^contact-buttons~'  => '~^contact-buttons~',
        '~^floating-bars~'    => '~^floating-bars~',
        '~^mega-menu~'        => '~^mega-menu~',
        '~^(loop|loop-carousel)$~' => '~^loop-~',
        '~^(load-more|ajax-pagination)$~' => '~^(posts|archive-posts|loop-grid)$~',
        '~^(woocommerce-|product-)~' => '~^(woocommerce-|wc-)~',
        '~^social$~'          => '~^facebook-~',
        '~^(posts|archive-posts|carousel)$~' => '~^(posts|archive-posts|media-carousel|testimonial-carousel|reviews)$~',
    ];

    private string $public;
    private array $stats = ['moved' => 0, 'removed' => 0, 'css_merged' => 0];

    public function __construct(private string $root, private Closure $log)
    {
        $this->public = rtrim($root, '/') . '/public';
    }

    /** @return array{moved:int, removed:int, css_merged:int, files_before:int, files_after:int, mb_before:float, mb_after:float} */
    public function run(): array
    {
        [$filesBefore, $bytesBefore] = $this->measure();

        $this->movePrefixes();
        $this->modernFonts();
        $this->pruneElementorChunks();
        $this->mergeCss();
        $this->stripVersions();
        $this->removeUnreferenced();

        [$filesAfter, $bytesAfter] = $this->measure();
        ($this->log)(sprintf('Pulizia: %d → %d file, %.1f → %.1f MB, %d CSS uniti in style.css',
            $filesBefore, $filesAfter, $bytesBefore / 1048576, $bytesAfter / 1048576, $this->stats['css_merged']));

        return $this->stats + ['files_before' => $filesBefore, 'files_after' => $filesAfter,
            'mb_before' => round($bytesBefore / 1048576, 1), 'mb_after' => round($bytesAfter / 1048576, 1)];
    }

    // ---------------------------------------------------------------- 1. percorsi

    private function movePrefixes(): void
    {
        foreach ($this->files($this->public) as $file) {
            $rel = substr($file, strlen($this->public));
            $new = self::mapPath($rel);
            if ($new !== $rel) {
                $this->move($file, $this->public . $new);
                $this->stats['moved']++;
            }
        }
        $map = [];
        foreach (self::PREFIXES as $from => $to) {
            $map[$from] = $to;
            $map[str_replace('/', '\\/', $from)] = str_replace('/', '\\/', $to); // config JSON negli script
        }
        $this->rewriteText(fn(string $s) => strtr($s, $map), true);
    }

    /**
     * File che Elementor carica da solo durante la navigazione (registro "AssetsLoader" nel suo JS):
     *   `${base}lib/dialog/dialog${min}.js` → lib/dialog/dialog.min.js
     * Non compaiono nell'HTML: vanno scaricati e non vanno mai cancellati.
     *
     * @return string[] percorsi relativi alla cartella assets del plugin
     */
    public static function loaderPaths(string $js): array
    {
        preg_match_all('~\$\{\w+\}((?:lib|css|js)/[\w./-]+?)(\$\{\w+\})?\.(css|js)(?=[?`])~', $js, $m, PREG_SET_ORDER);
        return array_values(array_unique(array_map(fn($x) => $x[1] . ($x[2] !== '' ? '.min' : '') . '.' . $x[3], $m)));
    }

    public static function mapPath(string $path): string
    {
        foreach (self::PREFIXES as $from => $to) {
            if (str_starts_with($path, $from)) {
                return $to . substr($path, strlen($from));
            }
        }
        return $path;
    }

    // ---------------------------------------------------------------- 2. font

    /** Nelle @font-face con un .woff2 restano solo .woff2 e local(); i file degli altri formati spariscono. */
    private function modernFonts(): void
    {
        $this->rewriteText(function (string $css, string $file) {
            if (!str_ends_with($file, '.css')) {
                return $css;
            }
            return preg_replace_callback('~@font-face\s*\{[^}]*\}~i', function (array $m) {
                $block = $m[0];
                if (!str_contains($block, '.woff2')) {
                    return $block;
                }
                $block = preg_replace('~src\s*:\s*url\([^)]*\.eot[^)]*\)\s*;~i', '', $block); // riga solo-IE
                return preg_replace_callback('~src\s*:([^;}]*)~i', function (array $s) {
                    $parts = array_filter(array_map('trim', explode(',', $s[1])),
                        fn($p) => str_contains($p, '.woff2') || str_starts_with($p, 'local('));
                    return 'src:' . implode(',', $parts);
                }, $block);
            }, $css);
        });
    }

    // ---------------------------------------------------------------- 3. JS di Elementor

    private function pruneElementorChunks(): void
    {
        $used = [];
        foreach ($this->textFiles(['html']) as $file) {
            preg_match_all('~data-widget_type="([\w-]+)\.~', file_get_contents($file), $m);
            $used += array_flip($m[1]);
        }
        if (!isset($used['lottie'])) {
            foreach (glob($this->public . '/assets/plugins/*/modules/lottie/assets/animations/*.json') ?: [] as $f) {
                unlink($f);
                $this->stats['removed']++;
            }
        }
        foreach (glob($this->public . '/assets/{elementor,elementor-pro}/js/*.bundle*.js', GLOB_BRACE) ?: [] as $chunk) {
            $name = preg_replace('~(\.[a-f0-9]{16,})?\.bundle(\.min)?\.js$~', '', basename($chunk));
            $editorOnly = str_ends_with($name, 'editor-handlers') || str_starts_with($name, 'editor');
            if ($editorOnly || $this->unusedWidgetChunk($name, array_keys($used))) {
                unlink($chunk);
                $this->stats['removed']++;
            }
        }
    }

    private function unusedWidgetChunk(string $chunk, array $usedWidgets): bool
    {
        foreach (self::WIDGET_CHUNKS as $chunkRe => $widgetRe) {
            if (preg_match($chunkRe, $chunk)) {
                $widgetRe ??= '~^' . preg_quote($chunk, '~') . '$~';
                return !preg_grep($widgetRe, $usedWidgets);
            }
        }
        return false;
    }

    // ---------------------------------------------------------------- 4. CSS unico

    private function mergeCss(): void
    {
        $htmlFiles = $this->textFiles(['html']);
        $order = [];
        $linkRe = '~<link\b[^>]*\brel=["\']stylesheet["\'][^>]*>~i';

        foreach ($htmlFiles as $file) {
            preg_match_all($linkRe, file_get_contents($file), $m);
            foreach ($m[0] as $tag) {
                $href = $this->mergeableHref($tag);
                if ($href !== null && !in_array($href, $order, true)) {
                    $order[] = $href;
                }
            }
        }
        if (!$order) {
            return;
        }

        $css = '';
        foreach ($order as $href) {
            $content = file_get_contents($this->public . $href);
            // url() relativi → assoluti: il file cambia cartella
            $dir = dirname($href);
            $content = preg_replace_callback('~url\(\s*([\'"]?)(?!data:|https?:|/|#)([^\'")]+)\1\s*\)~i',
                fn($u) => 'url(' . Url::normalizePath($dir . '/' . $u[2]) . ')', $content);
            $content = preg_replace('~@charset\s+[^;]+;~i', '', $content);
            $css .= "/* " . ltrim($href, '/') . " */\n" . trim($content) . "\n";
        }
        $target = '/assets/css/style.css';
        $this->write($this->public . $target, $css);
        $this->stats['css_merged'] = count($order);
        $versioned = $target . '?v=' . substr(md5($css), 0, 8); // cambia a ogni modifica: niente cache vecchia

        foreach ($htmlFiles as $file) {
            $html = file_get_contents($file);
            $done = false;
            $html = preg_replace_callback($linkRe, function (array $m) use (&$done, $versioned) {
                if ($this->mergeableHref($m[0]) === null) {
                    return $m[0];
                }
                if ($done) {
                    return '';
                }
                $done = true;
                return '<link rel="stylesheet" href="' . $versioned . '">';
            }, $html);
            file_put_contents($file, $html);
        }
    }

    /** CSS locale per tutti i media: si può unire. Stampa, CSS esterni e file con @import restano a parte. */
    private function mergeableHref(string $tag): ?string
    {
        if (!preg_match('~\bhref=["\']([^"\']+)["\']~i', $tag, $h) || !str_starts_with($h[1], '/') || str_starts_with($h[1], '//')) {
            return null;
        }
        if (preg_match('~\bmedia=["\']([^"\']*)["\']~i', $tag, $md) && !in_array(strtolower(trim($md[1])), ['', 'all', 'screen'], true)) {
            return null;
        }
        $href = strtok(html_entity_decode($h[1]), '?#');
        $file = $this->public . $href;
        if (!is_file($file) || stripos(file_get_contents($file), '@import') !== false || str_starts_with($href, '/assets/css/style.css')) {
            return null;
        }
        return $href;
    }

    // ---------------------------------------------------------------- 5. pulizia

    /** ?ver=… di WordPress sui file locali: inutili su un sito statico. */
    private function stripVersions(): void
    {
        $this->rewriteText(fn(string $s) => preg_replace('~((?:src|href)=["\']/[^"\'?]+)\?ver=[^"\'&]*(["\'])~', '$1$2', $s), false, ['html']);
    }

    /** Elimina i file che nessuna pagina, CSS, JS o articolo nomina. Due passaggi: un file tolto può liberarne altri. */
    private function removeUnreferenced(): void
    {
        for ($pass = 0; $pass < 2; $pass++) {
            $text = '';
            $relativeCss = [];
            foreach ($this->textFiles(['html', 'css', 'js', 'json']) as $file) {
                $content = str_replace('\\/', '/', file_get_contents($file));
                $text .= $content . "\n";
                if (str_ends_with($file, '.css')) {
                    $dir = dirname(substr($file, strlen($this->public)));
                    preg_match_all('~url\(\s*[\'"]?(?!data:|https?:|/|#)([^\'")?#]+)~i', $content, $m);
                    foreach ($m[1] as $ref) {
                        $relativeCss[Url::normalizePath($dir . '/' . $ref)] = true;
                    }
                }
            }

            // file caricati on-demand dal JS di Elementor (cartella base = quella sopra js/)
            $loader = [];
            foreach (glob($this->public . '/assets/{elementor,elementor-pro}/js/*.js', GLOB_BRACE) ?: [] as $js) {
                $base = dirname(substr($js, strlen($this->public)), 2) . '/';
                foreach (self::loaderPaths(file_get_contents($js)) as $p) {
                    $loader[$base . $p] = true;
                }
            }

            $removed = 0;
            foreach ($this->files($this->public) as $file) {
                $rel = substr($file, strlen($this->public));
                if (!preg_match('~^/(assets|media)/~', $rel) || $rel === '/assets/consent.js') {
                    continue;
                }
                // nei riferimenti i caratteri speciali possono essere codificati (spazi → %20);
                // il nome deve finire lì: "eicons.woff" non è usato solo perché esiste "eicons.woff2"
                $encoded = implode('/', array_map('rawurlencode', explode('/', $rel)));
                $exact = fn(string $p) => (bool) preg_match('~' . preg_quote($p, '~') . '(?![\w.-])~', $text);
                $used = $exact($rel) || ($encoded !== $rel && $exact($encoded))
                    || isset($relativeCss[$rel]) || isset($relativeCss[$encoded])
                    || isset($loader[$rel])
                    // JS caricati per nome dal runtime webpack
                    || (str_ends_with($rel, '.js') && str_contains($text, '"' . basename($rel) . '"'));
                if (!$used) {
                    unlink($file);
                    $removed++;
                }
            }
            $this->stats['removed'] += $removed;
            if (!$removed) {
                break;
            }
        }
        $this->removeEmptyDirs($this->public);
    }

    // ---------------------------------------------------------------- utilità

    /** @param string[] $exts */
    private function textFiles(array $exts): array
    {
        $out = [];
        foreach (['public', 'templates', 'content'] as $dir) {
            foreach ($this->files($this->root . '/' . $dir) as $f) {
                if (in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $exts, true)) {
                    $out[] = $f;
                }
            }
        }
        return $out;
    }

    /** @param Closure(string, string):string $fn */
    private function rewriteText(Closure $fn, bool $all = false, array $exts = ['css']): void
    {
        foreach ($this->textFiles($all ? ['html', 'css', 'js', 'json'] : $exts) as $file) {
            $content = file_get_contents($file);
            $new = $fn($content, $file);
            if ($new !== $content) {
                file_put_contents($file, $new);
            }
        }
    }

    private function files(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile()) {
                $out[] = $f->getPathname();
            }
        }
        sort($out);
        return $out;
    }

    private function move(string $from, string $to): void
    {
        if (!is_dir(dirname($to))) {
            mkdir(dirname($to), 0775, true);
        }
        rename($from, $to);
    }

    private function write(string $file, string $content): void
    {
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }
        file_put_contents($file, $content);
    }

    private function removeEmptyDirs(string $dir): void
    {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            if ($f->isDir() && count(scandir($f->getPathname())) === 2) {
                rmdir($f->getPathname());
            }
        }
    }

    private function measure(): array
    {
        $files = $this->files($this->public);
        return [count($files), array_sum(array_map('filesize', $files))];
    }
}

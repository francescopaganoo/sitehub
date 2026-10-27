<?php
declare(strict_types=1);

namespace Panel\Publish;

/**
 * Dove stanno le cose nel repo di un sito, e i controlli sui template del blog.
 *
 *   clone   sito prodotto dal clonatore: pagine in public/, site.json, templates/, content/ nella radice
 *   static  sito fatto a mano: pagine nella radice, tutto ciò che è di SiteHub in _sitehub/
 *           (template, copia degli articoli – anche le bozze –, mai pubblicato: lo escludono Dockerfile e nginx.conf)
 */
final class Layout
{
    /** Blog di un sito fatto a mano: tutto sotto /blog/, così un articolo non può sovrascrivere una pagina del sito. */
    public const STATIC_BLOG = ['index_path' => '/blog/', 'index_title' => 'Blog', 'per_page' => 9,
        'permalink' => '/blog/{slug}/', 'category_path' => '/blog/categoria/{slug}/'];

    private function __construct(
        public readonly string $name,
        public readonly string $pages,   // prefisso delle pagine generate
        public readonly string $data,    // prefisso di content/
        public readonly string $single,  // template dell'articolo
        public readonly string $archive, // template degli elenchi
        public readonly string $sitemap, // sitemap scritta da SiteHub
    ) {
    }

    public static function clone(): self
    {
        return new self('clone', 'public/', '', 'templates/single.html', 'templates/archive.html', 'sitemap.xml');
    }

    public static function static(): self
    {
        return new self('static', '', '_sitehub/', '_sitehub/templates/articolo.html', '_sitehub/templates/blog.html', 'sitemap-blog.xml');
    }

    public static function of(array $site): self
    {
        return ($site['config']['layout'] ?? 'clone') === 'static' ? self::static() : self::clone();
    }

    /** Sito fatto a mano: articoli e categorie devono stare sotto la pagina del blog. Restituisce l'errore o null. */
    public static function staticBlogError(array $blog): ?string
    {
        $base = $blog['index_path'] ?? null;
        if (!$base) {
            return 'Nei siti fatti a mano il blog ha sempre la sua pagina (index_path, es. "/blog/")';
        }
        foreach (['permalink', 'category_path'] as $key) {
            if (!str_starts_with((string) ($blog[$key] ?? ''), $base) || rtrim((string) $blog[$key], '/') . '/' === $base) {
                return "Nei siti fatti a mano $key deve stare sotto $base (es. \"{$base}{slug}/\"): così un articolo non può sostituire una pagina del sito";
            }
        }
        return null;
    }

    // ---------------------------------------------------------------- controllo dei template

    /** Segnaposti disponibili nei template (primo livello: {{image.url}} → image). */
    private const KNOWN = [
        // articolo (anche dentro {{#posts}} negli elenchi)
        'id', 'slug', 'title', 'content', 'excerpt', 'url', 'canonical', 'date_iso', 'date_human', 'modified_iso',
        'image', 'og_image', 'categories', 'author', 'seo_title', 'seo_description', 'robots', 'site_name', 'schema',
        // dentro {{#image}} e {{#categories}}
        'alt', 'width', 'height', 'name',
        // elenchi
        'archive_title', 'category', 'description', 'posts', 'empty_text', 'pagination',
    ];

    /**
     * Errori (il blog non può funzionare) e avvisi (funziona, ma manca qualcosa di utile).
     *
     * @return array{errors: string[], warnings: string[]}
     */
    public static function check(string $single, string $archive, self $layout): array
    {
        $errors = [];
        $warnings = [];
        $a = basename($layout->single);
        $b = basename($layout->archive);

        $has = fn(string $tpl, string $re) => (bool) preg_match($re, $tpl);
        if (!$has($single, '~\{\{\{\s*content\s*\}\}\}~')) {
            $errors[] = "manca {{{content}}} (il testo dell'articolo, con tre graffe) in $a";
        }
        if (!$has($single, '~\{\{\s*title\s*\}\}~')) {
            $errors[] = "manca {{title}} (il titolo dell'articolo) in $a";
        }
        if (!$has($archive, '~\{\{#\s*posts\s*\}\}.*\{\{/\s*posts\s*\}\}~s')) {
            $errors[] = "manca il blocco {{#posts}} … {{/posts}} (ripete ogni articolo) in $b";
        } elseif (!$has($archive, '~\{\{#\s*posts\s*\}\}.*\{\{\s*url\s*\}\}.*\{\{/\s*posts\s*\}\}~s')) {
            $errors[] = "dentro {{#posts}} … {{/posts}} manca il link all'articolo {{url}} in $b";
        }

        foreach ([$a => $single, $b => $archive] as $file => $tpl) {
            foreach (['seo_title' => 'il <title> della pagina', 'seo_description' => 'la meta description', 'canonical' => 'il link canonical'] as $name => $what) {
                if (!$has($tpl, '~\{\{\s*' . $name . '\s*\}\}~')) {
                    $warnings[] = "manca {{{$name}}} ($what) in $file";
                }
            }
            preg_match_all('~\{\{\{?\s*[#^/]?\s*([\w.]+)~', $tpl, $m);
            $unknown = array_diff(array_unique(array_map(fn($n) => explode('.', $n)[0], $m[1])), self::KNOWN);
            if ($unknown) {
                $warnings[] = 'segnaposti sconosciuti in ' . $file . ': ' . implode(', ', array_map(fn($n) => '{{' . $n . '}}', $unknown))
                    . ' (restano vuoti: forse un errore di battitura?)';
            }
        }
        if (!$has($archive, '~\{\{\{\s*pagination\s*\}\}\}~')) {
            $warnings[] = "manca {{{pagination}}} in $b: con molti articoli non si vedrebbero le pagine successive";
        }
        return ['errors' => $errors, 'warnings' => $warnings];
    }
}

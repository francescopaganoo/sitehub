<?php
declare(strict_types=1);

namespace Panel;

use Panel\Api\SitesApi;

/**
 * Guida per chi realizza i siti (docs/GUIDA-SITI.md), in due versioni dallo stesso testo:
 *   generica   tutte le regole, per un sito non ancora registrato
 *   del sito   solo le regole dei moduli attivi, con chiave, dominio e nome del sito già inseriti
 *
 * Nel file, i blocchi condizionali sono righe di commento HTML "se:NOME" … "/se" (vedi l'intestazione del file).
 */
final class Guide
{
    /** Blocchi della guida generica: le regole di tutti i moduli, nessuna delle varianti "senza". */
    private const GENERIC = ['generica', 'form', 'blog', 'consenso'];

    private const CATEGORY_NAMES = ['statistics' => 'statistiche', 'marketing' => 'marketing'];

    public static function file(): string
    {
        return dirname(__DIR__, 2) . '/docs/GUIDA-SITI.md';
    }

    public static function generic(): string
    {
        return self::render(self::GENERIC, [
            'https://hub.planstudios.it/e/v1/' => Embed::baseUrl() . '/e/' . Embed::VERSION . '/',
        ]);
    }

    public static function forSite(array $site): string
    {
        $modules = $site['modules'] ?? [];
        $cookies = SitesApi::cookieSettings($site);
        $seo = SitesApi::seoSettings($site);
        $categories = !empty($modules['cookies']) ? $cookies['categories'] : [];
        if ($seo['analytics'] === 'ga4' && !in_array('statistics', $categories, true)) {
            $categories[] = 'statistics'; // Google Analytics richiede il consenso
        }
        $categoryNames = implode(' e ', array_map(fn($c) => self::CATEGORY_NAMES[$c] ?? $c, $categories));
        if ($seo['analytics'] === 'ga4') {
            $categoryNames .= ' (Google Analytics)';
        }

        $blocks = ['sito', empty($modules['forms']) ? 'no-form' : 'form', empty($modules['blog']) ? 'no-blog' : 'blog'];
        $blocks = array_merge($blocks, $categories ? ['consenso', 'sito-consenso'] : ['tecnici']);
        $blocks[] = $seo['search_console'] === 'meta' ? 'sc-meta' : 'sc-dns';
        $blocks[] = match ($seo['analytics']) { 'ga4' => 'ga4', 'plausible' => 'plausible', default => 'no-analytics' };

        $summary = [
            empty($modules['forms']) ? 'nessun form di contatto' : 'form di contatto collegati a SiteHub',
            $categories ? "servizi con consenso dei visitatori ($categoryNames)" : 'solo cookie tecnici',
            empty($modules['blog']) ? 'nessun blog' : 'blog gestito da SiteHub',
            match ($seo['analytics']) { 'ga4' => 'statistiche con Google Analytics', 'plausible' => 'statistiche con Plausible (senza cookie)', default => 'nessuna statistica delle visite' },
        ];
        $domain = rtrim($site['domain'], '/');

        return self::render($blocks, [
            '<script src="https://hub.planstudios.it/e/v1/pk_XXXXXXXX.js" defer></script>' => Embed::snippet($site['public_key']),
            'https://www.dominio-cliente.it' => $domain,
            'dominio-cliente.it' => preg_replace('~^https?://(www\.)?~', '', $domain),
            '%NOME_SITO%' => $site['name'],
            '%DOMINIO%' => $domain,
            '%MODULI%' => implode(', ', $summary),
            '%URL_GUIDA%' => self::siteUrl($site['public_key']),
            '%CATEGORIE%' => $categoryNames,
            '%DOMINIO_NUDO%' => preg_replace('~^https?://(www\.)?~', '', $domain),
            '%SC_CODICE%' => $seo['search_console_code'] ?: 'CODICE-DI-VERIFICA',
            '%GA4_ID%' => $seo['ga4_id'] ?: 'G-XXXXXXXXXX',
        ]);
    }

    public static function siteUrl(string $publicKey): string
    {
        return Embed::baseUrl() . '/e/' . Embed::VERSION . "/$publicKey/guida.md";
    }

    /** @param string[] $blocks blocchi da tenere; gli altri spariscono con il loro contenuto */
    private static function render(array $blocks, array $replace): string
    {
        $text = (string) file_get_contents(self::file());
        $text = preg_replace('~\A<!--.*?-->\n~s', '', $text); // intestazione per chi modifica il file
        // blocchi dentro una riga ("e `sitemap-blog.xml`" solo col blog), poi quelli su più righe
        $text = preg_replace_callback('~<!-- se:([\w-]+) -->([^\n]*?)<!-- /se -->~', fn($m) => in_array($m[1], $blocks, true) ? $m[2] : '', $text);
        // dal blocco più interno all'esterno: il contenuto di un blocco non contiene altre aperture
        $block = '~^<!-- se:([\w-]+) -->\n((?:(?!^<!-- se:)[\s\S])*?)^<!-- /se -->\n~m';
        do {
            $text = preg_replace_callback($block, fn($m) => in_array($m[1], $blocks, true) ? $m[2] : '', $text, -1, $count);
        } while ($count > 0);
        $text = strtr($text, $replace);
        return preg_replace("~\n{3,}~", "\n\n", $text);
    }
}

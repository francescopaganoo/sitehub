<?php
declare(strict_types=1);

/*
 * Modulo 1 — clona un sito WordPress in HTML statico.
 *
 *   php clone.php https://sito.it [--slug=sito] [--out=sites/sito]
 *                 [--domain=https://sito.it] [--embed=https://pannello/e/v1/pk_xxx.js]
 *                 [--exclude=~regex~] [--no-local-fonts]
 */

if (PHP_VERSION_ID < 80400) {
    fwrite(STDERR, "Serve PHP 8.4+ (Dom\\HTMLDocument)\n");
    exit(1);
}

// fuso di default; se WordPress dichiara il suo, il blog usa quello
date_default_timezone_set(getenv('TZ') ?: 'Europe/Rome');

require __DIR__ . '/lib/Http.php';
require __DIR__ . '/lib/Url.php';
require __DIR__ . '/lib/Consent.php';
require __DIR__ . '/lib/Template.php';
require __DIR__ . '/lib/BlogRenderer.php';
require __DIR__ . '/lib/BlogExtractor.php';
require __DIR__ . '/lib/Restructure.php';
require __DIR__ . '/lib/Cloner.php';

$url = null;
$opt = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('~^--([\w-]+)(?:=(.*))?$~s', $arg, $m)) {
        $opt[$m[1]][] = $m[2] ?? true;
    } else {
        $url = $arg;
    }
}

if (!$url || !preg_match('~^https?://~', $url)) {
    fwrite(STDERR, "Uso: php clone.php https://sito.it [--slug=…] [--out=…] [--domain=…] [--embed=…]\n");
    exit(1);
}

$host = preg_replace('~^www\.~', '', parse_url($url, PHP_URL_HOST));
$slug = $opt['slug'][0] ?? preg_replace('~[^a-z0-9]+~', '-', strtolower(explode('.', $host)[0]));
$out = $opt['out'][0] ?? __DIR__ . '/sites/' . $slug;

if (is_dir("$out/public")) {
    // ogni clone parte pulito: i file obsoleti non devono restare nel repo del sito
    exec('rm -rf ' . escapeshellarg("$out/public"));
}

$report = (new Cloner([
    'url'           => $url,
    'out'           => $out,
    'slug'          => $slug,
    'domain'        => $opt['domain'][0] ?? null,
    'embed'         => $opt['embed'][0] ?? null,
    'exclude'       => $opt['exclude'] ?? [],
    'local_fonts'   => !isset($opt['no-local-fonts']),
]))->run();

$s = $report['stats'];
if ($report['forms'] && !$report['embed']) {
    echo "\nATTENZIONE: il sito ha form ma manca --embed: i form non invieranno finché non aggiungi lo script del pannello\n";
}
echo "\nFatto: {$s['pages']} pagine, {$s['files_after']} file, {$s['mb_after']} MB in {$s['seconds']}s\n";
echo 'Form: ' . count($report['forms']) . ' · Errori: ' . count($report['errors']) . " · Report: $out/site.json\n";

<?php
declare(strict_types=1);

/*
 * Modalità "ricostruzione": dal clone fedele a un sito pulito con pochissimi file.
 *
 *   php clone.php https://sito.it            → sites/sito          (copia fedele)
 *   php rebuild.php sites/sito               → sites/sito-pulito   (ricostruzione)
 *   php rebuild.php sites/sito --out=…
 */

if (PHP_VERSION_ID < 80400) {
    fwrite(STDERR, "Serve PHP 8.4+ (Dom\\HTMLDocument)\n");
    exit(1);
}
date_default_timezone_set(getenv('TZ') ?: 'Europe/Rome');

foreach (['Http', 'Url', 'Template', 'BlogRenderer', 'Rebuild/Css', 'Rebuild/Theme', 'Rebuild/Icons',
             'Rebuild/Extractor', 'Rebuild/Renderer', 'Rebuild/Rebuilder'] as $lib) {
    require __DIR__ . "/lib/$lib.php";
}

$src = null;
$opt = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('~^--([\w-]+)=(.*)$~s', $arg, $m)) {
        $opt[$m[1]] = $m[2];
    } else {
        $src = rtrim($arg, '/');
    }
}
if (!$src || !is_file("$src/site.json")) {
    fwrite(STDERR, "Uso: php rebuild.php sites/sito [--out=sites/sito-pulito]\n(serve prima il clone: php clone.php https://sito.it)\n");
    exit(1);
}

$result = (new Rebuild\Rebuilder($src, $opt['out'] ?? "$src-pulito", fn($m) => print("$m\n")))->run();

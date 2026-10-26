<?php
declare(strict_types=1);

/*
 * Avvio del pannello (Modulo 2). Nessuna dipendenza: solo PHP 8.4 con pdo_sqlite.
 *
 * Variabili d'ambiente:
 *   PANEL_DB       percorso del database SQLite (default panel/data/panel.sqlite)
 *   GITHUB_TOKEN   token per pubblicare sui repo dei siti (fine-grained, permesso "Contents: write")
 *   TZ             fuso orario degli articoli (default Europe/Rome)
 */

date_default_timezone_set(getenv('TZ') ?: 'Europe/Rome');

// classi condivise col clonatore (globali) + classi del pannello (namespace Panel\)
foreach (['Http', 'Url', 'Template', 'BlogRenderer'] as $lib) {
    require_once __DIR__ . "/../lib/$lib.php";
}
spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'Panel\\')) {
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, 6)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

function panel_db(): Panel\Db
{
    static $db = null;
    return $db ??= new Panel\Db(getenv('PANEL_DB') ?: __DIR__ . '/data/panel.sqlite');
}

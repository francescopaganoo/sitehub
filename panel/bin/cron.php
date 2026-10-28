<?php
declare(strict_types=1);

/*
 * Da eseguire ogni 5 minuti (Coolify: Scheduled Task "php panel/bin/cron.php").
 *  - ritenta l'invio dei messaggi dei form non ancora consegnati
 *  - pubblica i siti che hanno articoli programmati arrivati alla loro data
 */

require __DIR__ . '/../bootstrap.php';

$db = panel_db();
$store = new Panel\Store($db);

$retried = (new Panel\Forms\FormService($db))->retryFailed();
if ($retried) {
    echo date('c') . " form: $retried invii ritentati\n";
}

$publisher = new Panel\Publish\Publisher($store, $db, new Panel\Media($db, Panel\Media::root()));
foreach ($store->sites() as $site) {
    if (empty($site['templates']) || empty($site['modules']['blog'])) {
        continue;
    }
    date_default_timezone_set($site['config']['timezone'] ?? 'Europe/Rome');
    $since = $site['published_at'] ? strtotime($site['published_at']) : 0;
    $due = array_filter($store->allPosts($site['id']), fn($p) => $p['status'] === 'published'
        && strtotime($p['date']) <= time() && strtotime($p['date']) > $since);
    if (!$due) {
        continue;
    }
    try {
        $r = $publisher->publish($site, 'cron', 'Pannello: uscita articoli programmati');
        echo date('c') . " {$site['slug']}: pubblicati " . count($due) . " articoli programmati ({$r['written']} file)\n";
    } catch (Throwable $e) {
        echo date('c') . " {$site['slug']}: ERRORE pubblicazione – {$e->getMessage()}\n";
    }
}

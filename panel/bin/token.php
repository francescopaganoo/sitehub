<?php
declare(strict_types=1);

/*
 * Gestione token API.
 *   php panel/bin/token.php create "Gestionale"            token agenzia (tutti i siti)
 *   php panel/bin/token.php create "Cliente X" --site=slug token limitato a un sito
 *   php panel/bin/token.php list
 *   php panel/bin/token.php revoke <id>
 */

require __DIR__ . '/../bootstrap.php';

$db = panel_db();
$args = array_slice($argv, 1);
$site = null;
foreach ($args as $i => $a) {
    if (str_starts_with($a, '--site=')) {
        $site = substr($a, 7);
        unset($args[$i]);
    }
}
$args = array_values($args);

switch ($args[0] ?? '') {
    case 'create':
        $name = $args[1] ?? exit("Indica un nome: create \"Gestionale\"\n");
        $siteId = null;
        if ($site !== null) {
            $siteId = $db->one('SELECT id FROM sites WHERE slug = ?', [$site])['id'] ?? exit("Sito «{$site}» inesistente\n");
        }
        $token = (new Panel\Auth($db))->create($name, $siteId === null ? null : (int) $siteId);
        echo "Token creato ({$name}, " . ($site ? "sito {$site}" : 'agenzia') . "). Salvalo ora, non verrà più mostrato:\n\n$token\n";
        break;

    case 'list':
        foreach ($db->all('SELECT t.id, t.name, s.slug site, t.created_at, t.last_used_at, t.revoked_at
                           FROM tokens t LEFT JOIN sites s ON s.id = t.site_id ORDER BY t.id') as $t) {
            printf("%3d  %-25s %-15s creato %s  ultimo uso %s%s\n", $t['id'], $t['name'], $t['site'] ?? 'AGENZIA',
                substr($t['created_at'], 0, 10), $t['last_used_at'] ? substr($t['last_used_at'], 0, 16) : '-',
                $t['revoked_at'] ? '  [REVOCATO]' : '');
        }
        break;

    case 'revoke':
        $n = $db->run('UPDATE tokens SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL', [date('c'), (int) ($args[1] ?? 0)]);
        echo $n ? "Token revocato\n" : "Token non trovato o già revocato\n";
        break;

    default:
        echo "Uso: token.php create <nome> [--site=slug] | list | revoke <id>\n";
}

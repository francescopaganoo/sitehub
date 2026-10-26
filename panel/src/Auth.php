<?php
declare(strict_types=1);

namespace Panel;

use Panel\Http\ApiError;
use Panel\Http\Request;

/**
 * Token Bearer. Nel database c'è solo l'hash: il token in chiaro si vede una volta, alla creazione.
 *   site_id NULL → token agenzia: tutti i siti (es. il gestionale)
 *   site_id N    → token limitato a un sito
 */
final class Auth
{
    public function __construct(private Db $db)
    {
    }

    /** @return array{id:int, name:string, site_id:?int} */
    public function authenticate(Request $req): array
    {
        if (!preg_match('~^Bearer\s+(\S+)$~i', $req->header('authorization') ?? '', $m)) {
            throw new ApiError(401, 'unauthorized', 'Token mancante: usa l\'header "Authorization: Bearer <token>"');
        }
        $token = $this->db->one('SELECT id, name, site_id FROM tokens WHERE hash = ? AND revoked_at IS NULL', [hash('sha256', $m[1])]);
        if (!$token) {
            throw new ApiError(401, 'unauthorized', 'Token non valido o revocato');
        }
        $this->db->run('UPDATE tokens SET last_used_at = ? WHERE id = ?', [date('c'), $token['id']]);
        return ['id' => (int) $token['id'], 'name' => $token['name'], 'site_id' => $token['site_id'] === null ? null : (int) $token['site_id']];
    }

    public static function isAgency(array $principal): bool
    {
        return $principal['site_id'] === null;
    }

    public static function requireAgency(array $principal): void
    {
        if (!self::isAgency($principal)) {
            throw new ApiError(403, 'forbidden', 'Operazione riservata ai token agenzia');
        }
    }

    /** @return string il token in chiaro */
    public function create(string $name, ?int $siteId): string
    {
        $token = 'ssg_' . bin2hex(random_bytes(24));
        $this->db->insert('tokens', ['name' => $name, 'hash' => hash('sha256', $token), 'site_id' => $siteId, 'created_at' => date('c')]);
        return $token;
    }
}

<?php
declare(strict_types=1);

namespace Panel\Mail;

use Panel\Crypto;
use Panel\Db;
use Panel\Http\ApiError;

/**
 * Account SMTP riutilizzabili: le credenziali (es. l'SMTP di un account Brevo) si salvano una volta
 * e i siti le scelgono, indicando solo mittente e destinatari. Cambiare la chiave qui vale per tutti.
 * Un sito può comunque avere credenziali proprie (mail_settings senza smtp_account_id).
 */
final class SmtpAccounts
{
    public const BREVO = ['host' => 'smtp-relay.brevo.com', 'port' => 587, 'encryption' => 'tls'];

    public function __construct(private Db $db)
    {
    }

    /**
     * Valida host/porta/cifratura/login di un input SMTP ("preset": "brevo" compila i primi tre).
     *
     * @return array{host:string, port:int, encryption:string, username:string}
     */
    public static function validate(array $in, array &$errors): array
    {
        if (($in['preset'] ?? null) === 'brevo') {
            $in += self::BREVO;
        }
        $s = [
            'host'       => trim((string) ($in['host'] ?? '')),
            'port'       => (int) ($in['port'] ?? 0),
            'encryption' => (string) ($in['encryption'] ?? 'tls'),
            'username'   => trim((string) ($in['username'] ?? '')),
        ];
        if (!preg_match('~^[a-z0-9.-]+$~i', $s['host'])) {
            $errors['host'] = 'Obbligatorio, es. smtp-relay.brevo.com (oppure "preset": "brevo")';
        }
        if ($s['port'] < 1 || $s['port'] > 65535) {
            $errors['port'] = 'Porta non valida (Brevo: 587)';
        }
        if (!in_array($s['encryption'], ['tls', 'ssl', 'none'], true)) {
            $errors['encryption'] = 'Valori ammessi: tls (STARTTLS), ssl, none';
        }
        return $s;
    }

    public function all(): array
    {
        return array_map(fn($r) => $this->decode($r), $this->db->all('SELECT * FROM smtp_accounts ORDER BY name'));
    }

    public function get(int $id, bool $withPassword = false): array
    {
        $row = $this->db->one('SELECT * FROM smtp_accounts WHERE id = ?', [$id]);
        if (!$row) {
            throw ApiError::notFound('Account SMTP');
        }
        return $this->decode($row, $withPassword);
    }

    /** POST (id null) o PATCH: solo i campi inviati; password assente = resta quella salvata. */
    public function save(?int $id, array $in): array
    {
        $current = $id === null ? null : $this->get($id, true);
        $errors = [];
        $merged = $current ? array_merge($current, $in) : $in;
        $smtp = self::validate($merged, $errors);
        $name = trim(strip_tags((string) ($merged['name'] ?? '')));
        if ($name === '') {
            $errors['name'] = 'Obbligatorio, es. "Brevo Plan Studios"';
        } elseif ($this->db->one('SELECT 1 FROM smtp_accounts WHERE name = ? AND id != ?', [$name, $id ?? 0])) {
            $errors['name'] = 'Esiste già un account con questo nome';
        }
        if ($errors) {
            throw ApiError::validation($errors);
        }

        $row = $smtp + ['name' => $name, 'updated_at' => date('c')];
        if (array_key_exists('password', $in)) {
            $row['password'] = (string) $in['password'] === '' ? '' : Crypto::encrypt((string) $in['password']);
        }
        if ($id === null) {
            $id = (int) $this->db->insert('smtp_accounts', $row + ['password' => '', 'created_at' => date('c')]);
        } else {
            $this->db->update('smtp_accounts', $row, 'id = :id', ['id' => $id]);
        }
        return $this->get($id);
    }

    public function delete(int $id): void
    {
        $account = $this->get($id);
        if ($account['sites']) {
            throw new ApiError(409, 'account_in_use', 'Account usato dai siti: ' . implode(', ', $account['sites']) . '. Cambia prima il loro invio email.');
        }
        $this->db->run('DELETE FROM smtp_accounts WHERE id = ?', [$id]);
    }

    private function decode(array $row, bool $withPassword = false): array
    {
        $out = [
            'id'           => (int) $row['id'],
            'name'         => $row['name'],
            'host'         => $row['host'],
            'port'         => (int) $row['port'],
            'encryption'   => $row['encryption'],
            'username'     => $row['username'],
            'has_password' => $row['password'] !== '',
            // siti che lo usano: per sapere cosa tocca una modifica e impedire eliminazioni sbagliate
            'sites'        => array_column($this->db->all('SELECT s.slug FROM mail_settings m JOIN sites s ON s.id = m.site_id
                WHERE m.smtp_account_id = ? ORDER BY s.slug', [$row['id']]), 'slug'),
            'created_at'   => $row['created_at'],
            'updated_at'   => $row['updated_at'],
        ];
        if ($withPassword) {
            $out['password'] = $row['password'] !== '' ? (Crypto::decrypt($row['password']) ?? '') : '';
        }
        return $out;
    }
}

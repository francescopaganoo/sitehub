<?php
declare(strict_types=1);

namespace Panel\Forms;

use Panel\Crypto;
use Panel\Db;
use Panel\Http\ApiError;
use Panel\Mail\Message;
use Panel\Mail\Smtp;
use Panel\Mail\SmtpError;

/**
 * Modulo form: riceve gli invii dai siti, li salva SEMPRE prima di spedire,
 * poi invia via SMTP del sito (Brevo, dal dominio del cliente).
 * Se l'invio fallisce il messaggio resta "failed" e il cron riprova: nessun contatto va perso.
 */
final class FormService
{
    /** Campi tecnici di WordPress/plugin: non sono dati del visitatore. */
    private const JUNK = ['post_id', 'form_id', 'queried_id', 'referer_title', 'referrer', 'action', 'g-recaptcha-response',
        'cf-turnstile-response', 'h-captcha-response', '_wpnonce', '_wp_http_referer'];

    public const RATE_LIMIT = 5;          // invii per IP…
    public const RATE_WINDOW = 600;       // …in 10 minuti
    public const MIN_FILL_MS = 2000;      // sotto i 2 secondi è un bot
    public const MAX_ATTEMPTS = 5;

    public function __construct(private Db $db)
    {
    }

    // ---------------------------------------------------------------- ricezione

    /**
     * @param array{form:string, page:string, t:?int, hp:string, fields:array} $payload vedi normalize()
     * @return array{id:int, status:string, message:string}
     */
    public function submit(array $site, array $payload, string $ip, string $userAgent): array
    {
        $ipHash = Crypto::hash($ip);
        $recent = (int) $this->db->one('SELECT COUNT(*) n FROM submissions WHERE site_id = ? AND ip_hash = ? AND created_at > ?',
            [$site['id'], $ipHash, date('c', time() - self::RATE_WINDOW)])['n'];
        if ($recent >= self::RATE_LIMIT) {
            throw new ApiError(429, 'too_many_requests', 'Troppi invii, riprova tra qualche minuto');
        }

        $fields = array_values(array_filter($payload['fields'], fn($f) => !$f['honeypot']));
        $spam = $payload['hp'] !== ''
            || array_filter($payload['fields'], fn($f) => $f['honeypot'] && $f['value'] !== '')
            || ($payload['t'] !== null && $payload['t'] < self::MIN_FILL_MS);
        if (!array_filter($fields, fn($f) => $f['value'] !== '')) {
            throw ApiError::validation(['fields' => 'Il modulo è vuoto']);
        }

        $form = $this->ensureForm($site['id'], $payload['form']);
        if (!$form['enabled']) {
            throw new ApiError(403, 'form_disabled', 'Questo modulo è disattivato');
        }

        $id = (int) $this->db->insert('submissions', [
            'site_id'    => $site['id'],
            'form_key'   => $form['key'],
            'fields'     => json_encode($fields, JSON_UNESCAPED_UNICODE),
            'reply_to'   => self::findEmail($fields),
            'page'       => mb_substr($payload['page'], 0, 500),
            'ip_hash'    => $ipHash,
            'user_agent' => mb_substr($userAgent, 0, 300),
            'status'     => $spam ? 'spam' : 'pending',
            'created_at' => date('c'),
        ]);

        // a un bot si risponde "ok" come a tutti: non deve capire di essere stato scartato
        $status = $spam ? 'spam' : $this->deliver($id);
        return ['id' => $id, 'status' => $status, 'message' => $form['success_message']];
    }

    /**
     * Payload da embed.js (JSON) o da un form HTML senza JavaScript (POST classico).
     *
     * @return array{form:string, page:string, t:?int, hp:string, fields:array{name:string,label:string,type:string,value:string,honeypot:bool}[]}
     */
    public static function normalize(array $in, bool $fromScript, string $referer): array
    {
        $fields = [];
        if ($fromScript) {
            foreach (array_slice(is_array($in['fields'] ?? null) ? $in['fields'] : [], 0, 60) as $f) {
                if (!is_array($f) || !isset($f['name']) || in_array($f['name'], self::JUNK, true) || str_starts_with((string) $f['name'], '_')) {
                    continue;
                }
                $fields[] = self::field((string) $f['name'], (string) ($f['label'] ?? ''), (string) ($f['type'] ?? 'text'),
                    $f['value'] ?? '', (bool) ($f['honeypot'] ?? false));
            }
        } else {
            foreach (self::flatten($in) as $name => $value) {
                if (!in_array($name, self::JUNK, true) && !str_starts_with($name, '_') && count($fields) < 60) {
                    $fields[] = self::field($name, '', 'text', $value, false);
                }
            }
        }

        return [
            'form'   => self::formKey((string) ($in['form'] ?? $in['_form'] ?? '')),
            'page'   => (string) ($in['page'] ?? $in['_page'] ?? $referer),
            't'      => isset($in['t']) && is_numeric($in['t']) ? (int) $in['t'] : null,
            'hp'     => trim((string) ($in['hp'] ?? $in['_hp'] ?? '')),
            'fields' => $fields,
        ];
    }

    private static function field(string $name, string $label, string $type, mixed $value, bool $honeypot): array
    {
        $value = is_array($value) ? implode(', ', array_map('strval', array_filter($value, 'is_scalar'))) : (string) $value;
        return [
            'name'     => mb_substr($name, 0, 100),
            'label'    => mb_substr(trim(rtrim(trim($label), '*:')) ?: self::humanize($name), 0, 150),
            'type'     => preg_match('~^[a-z-]{1,20}$~', $type) ? $type : 'text',
            'value'    => mb_substr(trim($value), 0, 5000),
            'honeypot' => $honeypot,
        ];
    }

    /** form_fields[telefono] → "Telefono", nome_cognome → "Nome cognome" */
    private static function humanize(string $name): string
    {
        $name = preg_replace('~\[\]$~', '', $name);
        if (preg_match('~\[([^\]]+)\]$~', $name, $m)) {
            $name = $m[1];
        }
        $name = trim(preg_replace('~[_\-\s]+~', ' ', $name));
        return mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1);
    }

    /** a[b][c]=x → "a[b][c]" => x (i POST classici arrivano già annidati da PHP) */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $key = $prefix === '' ? (string) $k : "{$prefix}[$k]";
            if (is_array($v) && !array_is_list($v)) {
                $out += self::flatten($v, $key);
            } else {
                $out[$key] = $v;
            }
        }
        return $out;
    }

    public static function formKey(string $key): string
    {
        $key = trim(preg_replace('~[^a-z0-9]+~', '-', strtolower($key)), '-');
        return substr($key, 0, 64) ?: 'contatti';
    }

    private static function findEmail(array $fields): ?string
    {
        foreach ([fn($f) => $f['type'] === 'email', fn($f) => stripos($f['name'] . $f['label'], 'mail') !== false] as $match) {
            foreach ($fields as $f) {
                if ($match($f) && filter_var($f['value'], FILTER_VALIDATE_EMAIL)) {
                    return $f['value'];
                }
            }
        }
        return null;
    }

    // ---------------------------------------------------------------- invio

    /** Invia (o reinvia) un messaggio salvato. @return string stato finale: sent | failed */
    public function deliver(int $submissionId): string
    {
        $sub = $this->db->one('SELECT * FROM submissions WHERE id = ?', [$submissionId]);
        $site = $this->db->one('SELECT id, name, domain, config FROM sites WHERE id = ?', [$sub['site_id']]);
        $form = $this->form((int) $sub['site_id'], $sub['form_key']);
        $mail = $this->mailSettings((int) $sub['site_id'], true);
        $fields = json_decode($sub['fields'], true);

        try {
            if (!$mail) {
                throw new SmtpError('SMTP non configurato per questo sito');
            }
            $recipients = $form['recipients'] ?: $mail['recipients'];
            if (!$recipients) {
                throw new SmtpError('Nessun destinatario configurato');
            }
            $siteName = json_decode($site['config'], true)['site_name'] ?? $site['name'];
            $smtp = Smtp::fromSettings($mail);

            $msg = $this->notification($mail, $form, $siteName, $site['domain'], $sub, $fields);
            $msg->to = $recipients;
            $msg->replyTo = $sub['reply_to'];
            $smtp->send($msg);

            $auto = $form['autoreply'];
            // una sola volta: al primo invio riuscito (non ai reinvii manuali)
            if (!empty($auto['enabled']) && $sub['reply_to'] && $sub['sent_at'] === null) {
                $reply = new Message($mail['from_email'], $mail['from_name'] ?: $siteName,
                    $auto['subject'] ?: "Abbiamo ricevuto il tuo messaggio - $siteName", $auto['body'] ?? '');
                $reply->to = [$sub['reply_to']];
                $smtp->send($reply);
            }

            $this->db->run("UPDATE submissions SET status = 'sent', sent_at = ?, attempts = attempts + 1, last_error = NULL WHERE id = ?",
                [date('c'), $submissionId]);
            return 'sent';
        } catch (SmtpError $e) {
            $this->db->run("UPDATE submissions SET status = 'failed', attempts = attempts + 1, last_error = ? WHERE id = ?",
                [$e->getMessage(), $submissionId]);
            return 'failed';
        }
    }

    private function notification(array $mail, array $form, string $siteName, string $domain, array $sub, array $fields): Message
    {
        $subject = $form['subject'] ?: "Nuovo messaggio dal sito $siteName" . ($form['key'] !== 'contatti' ? " ({$form['name']})" : '');
        $text = "Nuovo messaggio dal modulo «{$form['name']}» di $domain\n\n";
        $rows = '';
        foreach ($fields as $f) {
            $text .= "{$f['label']}: {$f['value']}\n";
            $rows .= '<tr><td style="padding:8px 12px;border-bottom:1px solid #eee;color:#666;vertical-align:top;white-space:nowrap">'
                . htmlspecialchars($f['label']) . '</td><td style="padding:8px 12px;border-bottom:1px solid #eee">'
                . nl2br(htmlspecialchars($f['value'])) . '</td></tr>';
        }
        $page = $sub['page'] ? "\nPagina: {$sub['page']}" : '';
        $text .= "$page\nRicevuto: " . date('d/m/Y H:i', strtotime($sub['created_at']))
            . ($sub['reply_to'] ? "\n\nRispondi a questa email per scrivere direttamente a {$sub['reply_to']}." : '');

        $html = '<div style="font:15px/1.5 Arial,sans-serif;color:#222;max-width:640px">'
            . '<p>Nuovo messaggio dal modulo <strong>' . htmlspecialchars($form['name']) . '</strong> di ' . htmlspecialchars($domain) . '</p>'
            . '<table style="border-collapse:collapse;width:100%">' . $rows . '</table>'
            . '<p style="color:#888;font-size:13px">' . ($sub['page'] ? 'Pagina: ' . htmlspecialchars($sub['page']) . '<br>' : '')
            . 'Ricevuto: ' . date('d/m/Y H:i', strtotime($sub['created_at']))
            . ($sub['reply_to'] ? '<br>Rispondi a questa email per scrivere direttamente a ' . htmlspecialchars($sub['reply_to']) . '.' : '')
            . '</p></div>';

        return new Message($mail['from_email'], $mail['from_name'] ?: $siteName, $subject, $text, $html);
    }

    /** Cron: ritenta gli invii falliti con attesa crescente (10 min, 30 min, 1 h, 1 h 40…). @return int ritentati */
    public function retryFailed(): int
    {
        $n = 0;
        foreach ($this->db->all("SELECT id, attempts, created_at FROM submissions WHERE status IN ('failed', 'pending') AND attempts < ?",
            [self::MAX_ATTEMPTS]) as $s) {
            $last = strtotime($s['created_at']) + 600 * $s['attempts'] * ($s['attempts'] + 1) / 2;
            if (time() >= $last) {
                $this->deliver((int) $s['id']);
                $n++;
            }
        }
        return $n;
    }

    // ---------------------------------------------------------------- configurazione

    public function mailSettings(int $siteId, bool $withPassword = false): ?array
    {
        $row = $this->db->one('SELECT * FROM mail_settings WHERE site_id = ?', [$siteId]);
        if (!$row) {
            return null;
        }
        $row['recipients'] = json_decode($row['recipients'], true);
        $row['port'] = (int) $row['port'];
        $row['has_password'] = $row['password'] !== '';
        $row['password'] = $withPassword && $row['password'] !== '' ? (Crypto::decrypt($row['password']) ?? '') : null;
        unset($row['site_id']);
        if (!$withPassword) {
            unset($row['password']);
        }
        return $row;
    }

    public function saveMailSettings(int $siteId, array $s): void
    {
        $current = $this->db->one('SELECT password FROM mail_settings WHERE site_id = ?', [$siteId]);
        $row = [
            'site_id' => $siteId, 'host' => $s['host'], 'port' => $s['port'], 'encryption' => $s['encryption'],
            'username' => $s['username'], 'from_email' => $s['from_email'], 'from_name' => $s['from_name'],
            'recipients' => json_encode($s['recipients']), 'updated_at' => date('c'),
            // password assente = resta quella salvata
            'password' => array_key_exists('password', $s) ? ($s['password'] === '' ? '' : Crypto::encrypt($s['password'])) : ($current['password'] ?? ''),
        ];
        $this->db->run('DELETE FROM mail_settings WHERE site_id = ?', [$siteId]);
        $this->db->insert('mail_settings', $row);
    }

    public function forms(int $siteId): array
    {
        return array_map(fn($r) => $this->decodeForm($r), $this->db->all('SELECT * FROM forms WHERE site_id = ? ORDER BY name', [$siteId]));
    }

    public function form(int $siteId, string $key): array
    {
        $row = $this->db->one('SELECT * FROM forms WHERE site_id = ? AND key = ?', [$siteId, $key]);
        if (!$row) {
            throw ApiError::notFound('Modulo');
        }
        return $this->decodeForm($row);
    }

    /** I form si registrano da soli al primo invio: chi fa l'HTML non deve configurare nulla. */
    public function ensureForm(int $siteId, string $key, ?string $name = null): array
    {
        if (!$this->db->one('SELECT 1 FROM forms WHERE site_id = ? AND key = ?', [$siteId, $key])) {
            $this->db->insert('forms', ['site_id' => $siteId, 'key' => $key, 'name' => $name ?? self::humanize($key), 'created_at' => date('c')]);
        }
        return $this->form($siteId, $key);
    }

    public function updateForm(int $siteId, string $key, array $set): void
    {
        foreach (['recipients', 'autoreply'] as $json) {
            if (array_key_exists($json, $set)) {
                $set[$json] = json_encode($set[$json], JSON_UNESCAPED_UNICODE);
            }
        }
        if (array_key_exists('enabled', $set)) {
            $set['enabled'] = $set['enabled'] ? 1 : 0;
        }
        $this->db->update('forms', $set, 'site_id = :site AND key = :key', ['site' => $siteId, 'key' => $key]);
    }

    private function decodeForm(array $r): array
    {
        $auto = json_decode($r['autoreply'], true) ?: [];
        return [
            'key'             => $r['key'],
            'name'            => $r['name'],
            'enabled'         => (bool) $r['enabled'],
            'recipients'      => json_decode($r['recipients'], true),
            'subject'         => $r['subject'],
            'success_message' => $r['success_message'],
            'autoreply'       => ['enabled' => (bool) ($auto['enabled'] ?? false), 'subject' => $auto['subject'] ?? '', 'body' => $auto['body'] ?? ''],
            'created_at'      => $r['created_at'],
        ];
    }

    // ---------------------------------------------------------------- storico

    /** @return array{0: array[], 1: int} */
    public function submissions(int $siteId, array $filters, int $page, int $perPage): array
    {
        $where = 'site_id = :site';
        $params = ['site' => $siteId];
        foreach (['form' => 'form_key', 'status' => 'status'] as $f => $col) {
            if (!empty($filters[$f])) {
                $where .= " AND $col = :$f";
                $params[$f] = $filters[$f];
            }
        }
        $total = (int) $this->db->one("SELECT COUNT(*) n FROM submissions WHERE $where", $params)['n'];
        $rows = $this->db->all(sprintf('SELECT * FROM submissions WHERE %s ORDER BY id DESC LIMIT %d OFFSET %d',
            $where, $perPage, ($page - 1) * $perPage), $params);
        return [array_map(fn($r) => $this->decodeSubmission($r), $rows), $total];
    }

    public function submission(int $siteId, int $id): array
    {
        $row = $this->db->one('SELECT * FROM submissions WHERE site_id = ? AND id = ?', [$siteId, $id]);
        if (!$row) {
            throw ApiError::notFound('Messaggio');
        }
        return $this->decodeSubmission($row);
    }

    public function deleteSubmission(int $siteId, int $id): void
    {
        $this->db->run('DELETE FROM submissions WHERE site_id = ? AND id = ?', [$siteId, $id]);
    }

    private function decodeSubmission(array $r): array
    {
        return [
            'id'         => (int) $r['id'],
            'form'       => $r['form_key'],
            'status'     => $r['status'],
            'fields'     => array_map(fn($f) => array_intersect_key($f, array_flip(['name', 'label', 'type', 'value'])), json_decode($r['fields'], true)),
            'reply_to'   => $r['reply_to'],
            'page'       => $r['page'],
            'attempts'   => (int) $r['attempts'],
            'last_error' => $r['last_error'],
            'created_at' => $r['created_at'],
            'sent_at'    => $r['sent_at'],
        ];
    }
}

<?php
declare(strict_types=1);

namespace Panel\Mail;

/**
 * Client SMTP minimale: STARTTLS (587) o TLS implicito (465), AUTH LOGIN/PLAIN.
 * Pensato per Brevo (smtp-relay.brevo.com:587, utente = login SMTP, password = chiave SMTP),
 * ma funziona con qualunque server SMTP standard.
 */
final class Smtp
{
    /** @var resource|null */
    private $socket = null;

    public function __construct(
        private string $host,
        private int $port,
        private string $encryption, // tls | ssl | none
        private string $username = '',
        private string $password = '',
        private int $timeout = 20,
    ) {
    }

    public static function fromSettings(array $s): self
    {
        return new self($s['host'], (int) $s['port'], $s['encryption'], $s['username'], $s['password']);
    }

    /** @throws SmtpError */
    public function send(Message $msg): void
    {
        if (!$msg->to) {
            throw new SmtpError('Nessun destinatario');
        }
        try {
            $this->connect();
            $this->command('MAIL FROM:<' . $msg->fromEmail . '>', [250]);
            foreach ($msg->to as $rcpt) {
                $this->command("RCPT TO:<$rcpt>", [250, 251]);
            }
            $this->command('DATA', [354]);
            // dot-stuffing: una riga che inizia con "." chiuderebbe il messaggio
            $data = preg_replace('~^\.~m', '..', str_replace(["\r\n", "\r"], "\n", $msg->toMime()));
            $this->command(str_replace("\n", "\r\n", rtrim($data, "\n")) . "\r\n.", [250]);
            $this->command('QUIT', [221]);
        } finally {
            if ($this->socket) {
                fclose($this->socket);
                $this->socket = null;
            }
        }
    }

    private function connect(): void
    {
        $remote = ($this->encryption === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        $ctx = stream_context_create(['ssl' => ['peer_name' => $this->host, 'verify_peer' => true, 'verify_peer_name' => true]]);
        $socket = @stream_socket_client($remote, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$socket) {
            throw new SmtpError("Connessione a {$this->host}:{$this->port} fallita: $errstr");
        }
        $this->socket = $socket;
        stream_set_timeout($this->socket, $this->timeout);
        $this->expect([220]);

        $ehlo = $this->command('EHLO ' . (gethostname() ?: 'localhost'), [250]);
        if ($this->encryption === 'tls') {
            if (!str_contains($ehlo, 'STARTTLS')) {
                throw new SmtpError('Il server non supporta STARTTLS');
            }
            $this->command('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new SmtpError('Negoziazione TLS fallita');
            }
            $ehlo = $this->command('EHLO ' . (gethostname() ?: 'localhost'), [250]);
        }

        if ($this->username !== '') {
            if (preg_match('~AUTH[ =][^\r\n]*\bPLAIN\b~', $ehlo)) {
                $this->command('AUTH PLAIN ' . base64_encode("\0{$this->username}\0{$this->password}"), [235], true);
            } else {
                $this->command('AUTH LOGIN', [334]);
                $this->command(base64_encode($this->username), [334], true);
                $this->command(base64_encode($this->password), [235], true);
            }
        }
    }

    /** $secret: la riga contiene credenziali, non va citata negli errori */
    private function command(string $line, array $okCodes, bool $secret = false): string
    {
        fwrite($this->socket, $line . "\r\n");
        return $this->expect($okCodes, $secret ? null : strtok($line, ' '));
    }

    /** Legge una risposta (anche multi-riga "250-…") e verifica il codice. */
    private function expect(array $okCodes, ?string $after = null): string
    {
        $response = '';
        while (($line = fgets($this->socket, 1024)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $okCodes, true)) {
            $meta = stream_get_meta_data($this->socket);
            $why = $response === '' ? ($meta['timed_out'] ? 'timeout' : 'connessione chiusa') : trim($response);
            throw new SmtpError('SMTP' . ($after ? " dopo $after" : '') . ": $why");
        }
        return $response;
    }
}

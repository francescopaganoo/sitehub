<?php
declare(strict_types=1);

namespace Panel\Mail;

/** Email testo + HTML in UTF-8, pronta per il comando DATA di SMTP. */
final class Message
{
    /** @var string[] */
    public array $to = [];
    public ?string $replyTo = null;

    public function __construct(
        public string $fromEmail,
        public string $fromName,
        public string $subject,
        public string $text,
        public ?string $html = null,
    ) {
    }

    public function toMime(): string
    {
        $domain = substr(strrchr($this->fromEmail, '@') ?: '@localhost', 1);
        $headers = [
            'Date'         => date('r'),
            'From'         => self::address($this->fromEmail, $this->fromName),
            'To'           => implode(', ', array_map(fn($a) => self::address($a, ''), $this->to)),
            'Subject'      => self::encodeHeader($this->subject),
            'Message-ID'   => '<' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
            'MIME-Version' => '1.0',
        ];
        if ($this->replyTo) {
            $headers['Reply-To'] = self::address($this->replyTo, '');
        }

        $part = fn(string $type, string $body) => "Content-Type: $type; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . rtrim(chunk_split(base64_encode($body), 76, "\r\n")) . "\r\n";

        if ($this->html === null) {
            $body = $part('text/plain', $this->text);
        } else {
            $boundary = 'b_' . bin2hex(random_bytes(12));
            $headers['Content-Type'] = "multipart/alternative; boundary=\"$boundary\"";
            $body = "--$boundary\r\n" . $part('text/plain', $this->text)
                . "--$boundary\r\n" . $part('text/html', $this->html) . "--$boundary--\r\n";
        }

        $out = '';
        foreach ($headers as $k => $v) {
            $out .= "$k: $v\r\n";
        }
        // solo testo: la parte porta con sé Content-Type e riga vuota; multipart: riga vuota e poi le parti
        return $this->html === null ? $out . $body : $out . "\r\n" . $body;
    }

    private static function address(string $email, string $name): string
    {
        $email = self::clean($email);
        return $name === '' ? "<$email>" : self::encodeHeader($name) . " <$email>";
    }

    /** RFC 2047 per i caratteri non ASCII; mai a capo nei valori (header injection). */
    private static function encodeHeader(string $value): string
    {
        $value = self::clean($value);
        return preg_match('~[^\x20-\x7e]~', $value) ? '=?UTF-8?B?' . base64_encode($value) . '?=' : $value;
    }

    private static function clean(string $value): string
    {
        return trim(str_replace(["\r", "\n"], ' ', $value));
    }
}

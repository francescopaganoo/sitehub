<?php
declare(strict_types=1);

final class Http
{
    public const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    /**
     * GET con redirect seguiti. Ritorna sempre il body, anche per status != 200
     * (serve per salvare la pagina 404).
     *
     * @return array{status:int, type:string, url:string, body:string, error:string}
     */
    public static function get(string $url, int $retries = 2): array
    {
        for ($attempt = 0; ; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT        => 90,
                CURLOPT_USERAGENT      => self::UA,
                CURLOPT_ENCODING       => '',
            ]);
            $body = curl_exec($ch);
            $res = [
                'status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
                'type'   => (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
                'url'    => (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
                'body'   => is_string($body) ? $body : '',
                'error'  => curl_error($ch),
            ];

            $transient = $res['status'] === 0 || $res['status'] === 429 || $res['status'] >= 500;
            if (!$transient || $attempt >= $retries) {
                return $res;
            }
            sleep(1 + $attempt);
        }
    }

    /**
     * Richiesta generica per le API (GitHub, Brevo…). Un array come $body viene inviato in JSON.
     *
     * @param array<string,string> $headers
     * @return array{status:int, body:string, json:mixed, error:string}
     */
    public static function request(string $method, string $url, array $headers = [], array|string|null $body = null): array
    {
        if (is_array($body)) {
            $body = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headers['Content-Type'] = 'application/json';
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_USERAGENT      => 'static-site-gen-panel',
            CURLOPT_HTTPHEADER     => array_map(fn($k, $v) => "$k: $v", array_keys($headers), $headers),
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        $text = is_string($resp) ? $resp : '';
        return [
            'status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
            'body'   => $text,
            'json'   => json_decode($text, true),
            'error'  => curl_error($ch),
        ];
    }
}

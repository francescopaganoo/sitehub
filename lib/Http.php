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
}

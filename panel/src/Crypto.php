<?php
declare(strict_types=1);

namespace Panel;

/**
 * Cifratura dei segreti salvati nel database (password SMTP), con libsodium incluso in PHP.
 * Chiave: variabile PANEL_KEY (32 byte in base64); in sviluppo, se manca, viene creata in panel/data/secret.key.
 * Se la chiave cambia, i segreti salvati non sono più leggibili e vanno reinseriti.
 */
final class Crypto
{
    public static function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
    }

    public static function decrypt(string $cipher): ?string
    {
        $raw = base64_decode($cipher, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::key());
        return $plain === false ? null : $plain;
    }

    /** Hash non reversibile (es. IP dei visitatori: serve il limite di invii, non l'indirizzo). */
    public static function hash(string $value): string
    {
        return hash_hmac('sha256', $value, self::key());
    }

    private static function key(): string
    {
        static $key = null;
        if ($key !== null) {
            return $key;
        }
        $b64 = getenv('PANEL_KEY') ?: '';
        if ($b64 === '') {
            $file = __DIR__ . '/../data/secret.key';
            if (!is_file($file)) {
                @mkdir(dirname($file), 0775, true);
                file_put_contents($file, base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
                chmod($file, 0600);
            }
            $b64 = trim(file_get_contents($file));
        }
        $key = base64_decode($b64, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('PANEL_KEY non valida: servono 32 byte in base64 (openssl rand -base64 32)');
        }
        return $key;
    }
}

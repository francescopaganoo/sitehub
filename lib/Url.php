<?php
declare(strict_types=1);

final class Url
{
    /** Risolve un URL relativo rispetto a una base (RFC 3986, versione essenziale). */
    public static function resolve(string $base, string $rel): string
    {
        $rel = trim($rel);
        if ($rel === '') {
            return $base;
        }
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $rel)) {
            return $rel;
        }

        $b = parse_url($base);
        $scheme = $b['scheme'] ?? 'https';
        if (str_starts_with($rel, '//')) {
            return $scheme . ':' . $rel;
        }

        $authority = $scheme . '://' . ($b['host'] ?? '') . (isset($b['port']) ? ':' . $b['port'] : '');
        $basePath = $b['path'] ?? '/';

        if ($rel[0] === '#') {
            return strtok($base, '#') . $rel;
        }
        if ($rel[0] === '?') {
            return $authority . $basePath . $rel;
        }

        preg_match('~^([^?#]*)(.*)$~s', $rel, $m);
        [$path, $suffix] = [$m[1], $m[2]];
        if ($path === '' || $path[0] !== '/') {
            $path = preg_replace('~/[^/]*$~', '/', $basePath) . $path;
        }

        return $authority . self::normalizePath($path) . $suffix;
    }

    /** Rimuove i segmenti "." e "..". */
    public static function normalizePath(string $path): string
    {
        $out = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '..') {
                if (count($out) > 1) {
                    array_pop($out);
                }
            } elseif ($seg !== '.') {
                $out[] = $seg;
            }
        }
        $norm = implode('/', $out);
        if ($norm === '' || $norm[0] !== '/') {
            $norm = '/' . $norm;
        }
        if (preg_match('~/\.\.?$~', $path)) {
            $norm = rtrim($norm, '/') . '/';
        }
        return $norm;
    }

    /** Estensione del file in un path (senza query), in minuscolo. */
    public static function extension(string $path): string
    {
        $path = strtok($path, '?#') ?: '';
        return strtolower(pathinfo(basename($path), PATHINFO_EXTENSION));
    }
}

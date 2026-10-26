<?php
declare(strict_types=1);

namespace Panel;

final class Slug
{
    public static function make(string $text): string
    {
        $text = strtr(mb_strtolower(trim($text)), [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n', '’' => '-', "'" => '-', '&' => '-e-',
        ]);
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $slug = trim(preg_replace('~[^a-z0-9]+~', '-', $ascii !== false ? $ascii : $text), '-');
        return substr($slug, 0, 120) ?: 'articolo';
    }

    public static function valid(string $slug): bool
    {
        return (bool) preg_match('~^[a-z0-9]+(?:-[a-z0-9]+)*$~', $slug);
    }
}

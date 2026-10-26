<?php
declare(strict_types=1);

/**
 * Motore di template minimale, sottoinsieme di Mustache:
 *   {{nome}}            valore con escape HTML (anche dotted: {{image.url}})
 *   {{{nome}}}          valore senza escape (HTML del contenuto)
 *   {{#nome}}…{{/nome}} sezione: ripetuta per le liste, mostrata se il valore è "vero"
 *   {{^nome}}…{{/nome}} sezione inversa: mostrata se il valore è vuoto/falso
 *
 * Un solo passaggio: i valori inseriti non vengono rielaborati
 * (un articolo che contiene "{{" resta intatto).
 */
final class Template
{
    private const TOKEN = '~\{\{([#^])\s*([\w.]+)\s*\}\}(.*?)\{\{/\s*\2\s*\}\}|\{\{\{\s*([\w.]+)\s*\}\}\}|\{\{\s*([\w.]+)\s*\}\}~s';

    public static function render(string $tpl, array $data): string
    {
        return self::renderWith($tpl, [$data]);
    }

    /** @param array[] $stack contesti, dal più esterno al più interno */
    private static function renderWith(string $tpl, array $stack): string
    {
        return preg_replace_callback(self::TOKEN, function (array $m) use ($stack): string {
            if (isset($m[5])) {
                return htmlspecialchars(self::scalar(self::lookup($m[5], $stack)), ENT_QUOTES | ENT_HTML5);
            }
            if (isset($m[4]) && $m[4] !== '') {
                return self::scalar(self::lookup($m[4], $stack));
            }

            $value = self::lookup($m[2], $stack);
            $empty = $value === null || $value === false || $value === '' || $value === [];
            if ($m[1] === '^') {
                return $empty ? self::renderWith($m[3], $stack) : '';
            }
            if ($empty) {
                return '';
            }
            if (is_array($value) && array_is_list($value)) {
                $out = '';
                foreach ($value as $item) {
                    $out .= self::renderWith($m[3], is_array($item) ? [...$stack, $item] : $stack);
                }
                return $out;
            }
            return self::renderWith($m[3], is_array($value) ? [...$stack, $value] : $stack);
        }, $tpl);
    }

    private static function lookup(string $name, array $stack): mixed
    {
        $parts = explode('.', $name);
        for ($i = count($stack) - 1; $i >= 0; $i--) {
            if (!array_key_exists($parts[0], $stack[$i])) {
                continue;
            }
            $value = $stack[$i][$parts[0]];
            foreach (array_slice($parts, 1) as $p) {
                $value = is_array($value) ? ($value[$p] ?? null) : null;
            }
            return $value;
        }
        return null;
    }

    private static function scalar(mixed $v): string
    {
        return is_scalar($v) ? (is_bool($v) ? ($v ? '1' : '') : (string) $v) : '';
    }
}

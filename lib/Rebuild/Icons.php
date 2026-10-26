<?php
declare(strict_types=1);

namespace Rebuild;

use Http;

/**
 * Icone dei font (Font Awesome, eicons di Elementor) → SVG inline.
 * I glifi si leggono dai file .svg dei font sul sito originale: il sito ricostruito
 * non carica nessun font di icone (centinaia di KB per poche icone).
 */
final class Icons
{
    /** classe di stile → file del font, relativo al sito WordPress originale */
    private const FONTS = [
        'fas'   => '/wp-content/plugins/elementor/assets/lib/font-awesome/webfonts/fa-solid-900.svg',
        'far'   => '/wp-content/plugins/elementor/assets/lib/font-awesome/webfonts/fa-regular-400.svg',
        'fab'   => '/wp-content/plugins/elementor/assets/lib/font-awesome/webfonts/fa-brands-400.svg',
        'eicon' => '/wp-content/plugins/elementor/assets/lib/eicons/fonts/eicons.svg',
    ];

    /** @var array<string, ?array{ascent:int, units:int, glyphs:array<string,array{d:string, adv:int}>}> */
    private array $fonts = [];
    public array $missing = [];

    public function __construct(private string $origin, private string $cacheDir)
    {
    }

    /** "far fa-envelope" / "eicon-menu-bar" → <svg>…</svg> (stringa vuota se l'icona non si trova) */
    public function svg(string $classes, string $label = ''): string
    {
        $classes = ' ' . $classes . ' ';
        if (preg_match('~\beicon-([\w-]+)~', $classes, $m)) {
            [$style, $name] = ['eicon', $m[1]];
        } elseif (preg_match('~\bfa-([\w-]+)~', $classes, $m) && preg_match('~\b(fas|far|fab|fa-solid|fa-regular|fa-brands)\b~', $classes, $s)) {
            $style = ['fa-solid' => 'fas', 'fa-regular' => 'far', 'fa-brands' => 'fab'][$s[1]] ?? $s[1];
            $name = $m[1] === 'solid' || $m[1] === 'regular' || $m[1] === 'brands' ? '' : $m[1];
        } else {
            return '';
        }
        $font = $this->font($style);
        $glyph = $font['glyphs'][$name] ?? null;
        if (!$glyph) {
            $this->missing["$style $name"] = true;
            return '';
        }
        $a11y = $label === '' ? 'aria-hidden="true"' : 'role="img" aria-label="' . htmlspecialchars($label) . '"';
        return sprintf('<svg class="icon" viewBox="0 %d %d %d" %s><path transform="scale(1,-1)" d="%s"/></svg>',
            -$font['ascent'], $glyph['adv'], $font['units'], $a11y, $glyph['d']);
    }

    private function font(string $style): ?array
    {
        if (array_key_exists($style, $this->fonts)) {
            return $this->fonts[$style];
        }
        $cache = $this->cacheDir . '/' . md5($this->origin . self::FONTS[$style]) . '.svg';
        if (!is_file($cache)) {
            $res = Http::get($this->origin . self::FONTS[$style], 1);
            if ($res['status'] !== 200 || !str_contains($res['body'], '<glyph')) {
                return $this->fonts[$style] = null;
            }
            @mkdir($this->cacheDir, 0775, true);
            file_put_contents($cache, $res['body']);
        }
        $svg = file_get_contents($cache);

        preg_match('~<font\b[^>]*horiz-adv-x="(\d+)"~', $svg, $f);
        preg_match('~<font-face\b[^>]*units-per-em="(\d+)"[^>]*ascent="(-?\d+)"~', $svg, $face);
        $defaultAdv = (int) ($f[1] ?? 512);
        $glyphs = [];
        preg_match_all('~<glyph\b([^>]*)/?>~', $svg, $all);
        foreach ($all[1] as $attrs) {
            if (preg_match('~glyph-name="([^"]+)"~', $attrs, $n) && preg_match('~\sd="([^"]+)"~', $attrs, $d)) {
                preg_match('~horiz-adv-x="(\d+)"~', $attrs, $adv);
                $glyphs[$n[1]] = ['d' => $d[1], 'adv' => (int) ($adv[1] ?? $defaultAdv)];
            }
        }
        return $this->fonts[$style] = ['units' => (int) ($face[1] ?? 512), 'ascent' => (int) ($face[2] ?? 448), 'glyphs' => $glyphs];
    }
}

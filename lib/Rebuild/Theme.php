<?php
declare(strict_types=1);

namespace Rebuild;

/**
 * Design token del sito: palette e font globali del kit Elementor, tipografia di base del tema.
 * Diventano le variabili CSS (:root) del sito ricostruito.
 */
final class Theme
{
    /** @var array<string,string> colori: primary, secondary, text, accent (+ eventuali colori custom del kit) */
    public array $colors = [];
    /** @var array<string,array{family:?string, weight:?string}> primary, secondary, text, accent */
    public array $fonts = [];
    /** body: color, background, font-family, font-size, line-height, font-weight */
    public array $body = [];
    /** h1…h6: font-size (+ eventuali color, font-weight, font-family) */
    public array $headings = [];
    public array $heading = [];
    public string $link = '';
    public string $container = '1140px';
    public string $containerTablet = '1024px';
    public string $containerMobile = '767px';
    public string $widgetSpacing = '20px';

    public static function fromCss(Css $css): self
    {
        $t = new self();
        $kit = null;
        foreach ($css->rules as $r) {
            foreach ($r['selectors'] as $sel) {
                if (!$kit && preg_match('~^\.elementor-kit-(\d+)$~', $sel, $m)) {
                    $kit = $m[1];
                }
            }
        }
        $is = fn(string ...$want) => fn(string $sel) => in_array($sel, $want, true);
        $kitSel = fn(string $suffix) => $kit ? ".elementor-kit-$kit$suffix" : '__none__';

        // tema (es. Hello) e poi kit Elementor, che ha la precedenza
        $t->body = array_merge($css->collect($is('body')), $css->collect($is($kitSel(''))));
        // le regole comuni "h1,h2,…" vengono divise per selettore: h1 le contiene tutte
        $t->heading = $css->collect($is('h1'));
        foreach (range(1, 6) as $n) {
            $t->headings["h$n"] = array_merge($css->collect($is("h$n")), $css->collect($is($kitSel(" h$n"))));
        }
        $link = array_merge($css->collect($is('a')), $css->collect($is($kitSel(' a'))));
        $t->link = $link['color'] ?? '';

        foreach ($t->body as $k => $v) {
            if (preg_match('~^--e-global-color-([\w-]+)$~', $k, $m)) {
                $t->colors[$m[1]] = $v;
            } elseif (preg_match('~^--e-global-typography-(\w+)-font-(family|weight)$~', $k, $m)) {
                $t->fonts[$m[1]][$m[2]] = trim($v, '"\' ');
            }
        }
        // i token del kit non sono proprietà del body
        $t->body = array_filter($t->body, fn($k) => !str_starts_with($k, '--'), ARRAY_FILTER_USE_KEY);

        $max = $css->collect(fn($s) => $s === '.e-con' || $s === $kitSel(' .e-con') || $s === $kitSel(''));
        if (isset($max['--container-max-width'])) {
            $t->container = $max['--container-max-width'];
        }
        foreach (['containerTablet' => '(max-width:1024px)', 'containerMobile' => '(max-width:767px)'] as $prop => $media) {
            $v = $css->collect(fn($s) => $s === '.e-con', $media)['--container-max-width'] ?? null;
            if ($v) {
                $t->$prop = $v;
            }
        }
        $spacing = $css->collect($is($kitSel(''), '.elementor-widget:not(:last-child)'));
        $t->widgetSpacing = $spacing['--kit-widget-spacing'] ?? $spacing['margin-block-end'] ?? '20px';
        return $t;
    }

    /** Font family con fallback, es. "Montserrat", sans-serif */
    public function family(string $role): ?string
    {
        $f = $this->fonts[$role]['family'] ?? null;
        return $f ? "\"$f\", sans-serif" : null;
    }

    /** Famiglie e pesi realmente usati: servono per scaricare solo i font necessari. @return array<string,int[]> */
    public function usedFonts(): array
    {
        $out = [];
        foreach ($this->fonts as $f) {
            if (!empty($f['family'])) {
                $out[$f['family']][] = (int) ($f['weight'] ?? 400) ?: 400;
            }
        }
        return array_map(fn($w) => array_values(array_unique([...$w, 400, 700])), $out);
    }

    /** Variabili CSS globali. */
    public function rootCss(): string
    {
        $vars = [];
        foreach ($this->colors as $name => $value) {
            $vars["--c-$name"] = $value;
        }
        foreach ($this->fonts as $role => $f) {
            if (!empty($f['family'])) {
                $vars["--f-$role"] = $this->family($role);
            }
            if (!empty($f['weight'])) {
                $vars["--fw-$role"] = $f['weight'];
            }
        }
        $vars['--link'] = self::vars($this->link ?: 'inherit');
        $vars['--container'] = $this->container;
        $vars['--space'] = $this->widgetSpacing; // spazio predefinito tra gli elementi

        $css = ":root{\n";
        foreach ($vars as $k => $v) {
            $css .= "  $k: $v;\n";
        }
        $css .= "}\n";
        $css .= "@media (max-width:1024px){:root{--container: {$this->containerTablet}}}\n";
        $css .= "@media (max-width:767px){:root{--container: {$this->containerMobile}}}\n";
        return $css;
    }

    /** Tipografia di base: body, titoli, link. */
    public function baseCss(): string
    {
        $body = array_intersect_key($this->body, array_flip(['color', 'background-color', 'font-family', 'font-size', 'font-weight', 'line-height']));
        // il font "testo" del kit vale per tutto il sito: anche le pagine fatte senza Elementor restano coerenti
        if ($this->family('text')) {
            $body['font-family'] = 'var(--f-text)';
        }
        $css = 'body{margin:0;-webkit-font-smoothing:antialiased;' . self::decls($body) . "}\n";
        $css .= 'h1,h2,h3,h4,h5,h6{margin:.5rem 0 1rem;line-height:1.2;font-weight:500;color:inherit;font-family:inherit;'
            . self::decls(array_intersect_key($this->heading, array_flip(['font-weight', 'line-height', 'color', 'font-family']))) . "}\n";
        foreach ($this->headings as $h => $d) {
            $d = array_intersect_key($d, array_flip(['font-size', 'color', 'font-weight', 'font-family', 'line-height']));
            if ($d) {
                $css .= "$h{" . self::decls($d) . "}\n";
            }
        }
        return $css;
    }

    /** var( --e-global-color-primary ) → var(--c-primary), e simili. */
    public static function vars(string $value): string
    {
        $value = preg_replace('~var\(\s*--e-global-color-([\w-]+)\s*\)~', 'var(--c-$1)', $value);
        $value = preg_replace('~var\(\s*--e-global-typography-(\w+)-font-family\s*\)(\s*,\s*[\w-]+)?~', 'var(--f-$1)', $value);
        return preg_replace('~var\(\s*--e-global-typography-(\w+)-font-weight\s*\)~', 'var(--fw-$1)', $value);
    }

    public static function decls(array $d): string
    {
        $out = '';
        foreach ($d as $k => $v) {
            $out .= "$k:" . self::vars($v) . ';';
        }
        return $out;
    }
}

<?php
declare(strict_types=1);

namespace Rebuild;

/**
 * Modello (Extractor) → HTML semantico + CSS delle sezioni.
 * Ogni sezione ha un nome leggibile (sez-chi-siamo); un elemento riceve una classe propria
 * (sez-chi-siamo__titolo1) solo se ha stili diversi dai predefiniti del design system (base.css).
 */
final class Renderer
{
    private const TYPO = ['font-family', 'font-size', 'font-weight', 'line-height', 'letter-spacing', 'text-transform',
        'font-style', 'text-decoration', 'word-spacing', 'color'];
    private const BOX = '~^(margin|padding|background|border|box-shadow)~';
    private const SELF = ['text-align', 'width', 'max-width', 'min-width', 'height', 'align-self', 'order', 'flex-grow', 'flex-shrink', 'flex-basis'];
    private const HEADING_SIZES = ['small' => '15px', 'medium' => '19px', 'large' => '29px', 'xl' => '39px', 'xxl' => '59px'];

    /** @var array<string, array<string, array>> breakpoint → selettore → dichiarazioni */
    private array $rules = ['d' => [], 'u' => [], 't' => [], 'm' => []];
    private array $keyframes = [];
    private array $taken = [];
    private string $section = 'sez';
    private array $count = [];
    private bool $eager = true;
    private bool $eagerContext = false;

    /** URL di invio dei form (endpoint del pannello); se null resta quello del clone */
    public ?string $formAction = null;
    public bool $usesMenu = false;
    public bool $usesLightbox = false;

    public function __construct(private Theme $theme)
    {
    }

    /** @param string $context header | main | footer */
    public function document(array $nodes, string $context): string
    {
        // le immagini dell'header (logo) sono subito visibili: niente caricamento differito
        $this->eagerContext = $context === 'header';
        $html = '';
        foreach ($nodes as $i => $n) {
            if ($n['kind'] !== 'box') {
                $html .= $this->widget($n);
                continue;
            }
            $base = match ($context) {
                'header' => 'header',
                'footer' => 'footer',
                default  => 'sez-' . ($n['anchor'] ? self::slug($n['anchor']) : (self::slug(self::firstHeading($n)) ?: (string) ($i + 1))),
            };
            $this->section = $this->unique($base);
            $this->count = [];
            $html .= $this->box($n, $this->section, $context === 'main' ? 'section' : 'div') . "\n";
        }
        return $html;
    }

    public function css(): string
    {
        $out = '';
        foreach (['d' => null, 'u' => '(min-width:768px)', 't' => '(max-width:1024px)', 'm' => '(max-width:767px)'] as $bp => $media) {
            $block = '';
            foreach ($this->rules[$bp] as $sel => $decls) {
                $block .= ($media ? '  ' : '') . $sel . '{' . Theme::decls($decls) . "}\n";
            }
            if ($block !== '') {
                $out .= $media ? "\n@media $media{\n$block}\n" : $block;
            }
        }
        return $out . implode('', $this->keyframes);
    }

    // ---------------------------------------------------------------- box

    private function box(array $b, ?string $name, string $tag = 'div'): string
    {
        $hasOverlay = array_filter($b['overlay']['d']) || $b['slides'];
        $css = [];
        foreach (['d', 'u', 't', 'm'] as $bp) {
            $vars = $b['vars'][$bp];
            unset($vars['--overlay-opacity']); // finisce nell'opacità di ::before
            if ($bp === 'd') {
                // sul desktop si scrive solo ciò che differisce dai predefiniti di .box (base.css)
                $vars = array_filter($vars, fn($v, $k) => !self::isDefault($k, $v), ARRAY_FILTER_USE_BOTH);
            }
            $css[$bp] = array_merge($vars, $b['bg'][$bp]);
        }
        $needsName = array_filter($css['d']) || array_filter($css['u']) || array_filter($css['t']) || array_filter($css['m']) || $hasOverlay;
        $name ??= $needsName ? $this->el('box') : null;

        $classes = array_filter(['box', $name, $b['boxed'] ? 'box--boxed' : null, $hasOverlay ? 'has-overlay' : null,
            $b['sticky'] ? 'is-sticky' : null, ...self::hideClasses($b['hide'])]);

        if ($name) {
            $this->add(".$name", $css);
            if (array_filter($b['overlay']['d'])) {
                $ov = $b['overlay'];
                $opacity = $b['vars']['d']['--overlay-opacity'] ?? null;
                if ($opacity !== null && !isset($ov['d']['opacity'])) {
                    $ov['d']['opacity'] = $opacity;
                }
                $this->add(".$name::before", $ov);
            }
        }

        $slides = $b['slides'] && $name ? $this->slides($b['slides'], $name) : '';
        $kids = '';
        foreach ($b['children'] as $child) {
            $kids .= $child['kind'] === 'box' ? $this->box($child, null) : $this->widget($child);
        }
        $id = $b['anchor'] ? ' id="' . htmlspecialchars($b['anchor']) . '"' : '';
        $content = $b['boxed'] ? "<div class=\"inner\">$kids</div>" : $kids;
        return "<$tag class=\"" . implode(' ', $classes) . "\"$id>$slides$content</$tag>";
    }

    /** Slideshow di sfondo in solo CSS (dissolvenza ed eventuale zoom "Ken Burns"). */
    private function slides(array $s, string $name): string
    {
        $n = count($s['urls']);
        $dur = max(1, $s['duration']) / 1000;
        $total = $n * $dur;
        $key = "slides{$n}" . ($s['kenburns'] ? 'kb' : '');
        if (!isset($this->keyframes[$key])) {
            $fade = round(0.5 / $total * 100, 2);
            $show = round(100 / $n, 2);
            $zoomStart = $s['kenburns'] ? 'transform:scale(1);' : '';
            $zoomEnd = $s['kenburns'] ? 'transform:scale(1.3);' : '';
            $this->keyframes[$key] = "@keyframes $key{0%{opacity:0;$zoomStart}$fade%{opacity:1}$show%{opacity:1}"
                . round($show + $fade, 2) . "%{opacity:0;$zoomEnd}100%{opacity:0;$zoomEnd}}\n";
        }
        $this->rules['d'][".$name .slides>div"] = ['animation' => "$key {$total}s linear infinite"];
        $html = '<div class="slides" aria-hidden="true">';
        foreach ($s['urls'] as $i => $url) {
            $this->rules['d'][".$name .slides>div:nth-child(" . ($i + 1) . ')'] = ['background-image' => "url(\"$url\")", 'animation-delay' => ($i * $dur) . 's'];
            $html .= '<div></div>';
        }
        // la prima immagine è visibile subito, anche prima dell'animazione
        $this->rules['d'][".$name .slides>div:first-child"]['opacity'] = '1';
        return $html . '</div>';
    }

    /** Valori uguali ai predefiniti di .box in base.css: inutile ripeterli. */
    private static function isDefault(string $prop, string $value): bool
    {
        $v = preg_replace('~\s+~', '', $value);
        $defaults = ['--dir' => 'column', '--wrap' => 'nowrap', '--justify' => 'normal', '--align' => 'normal', '--wrap-m' => 'wrap',
            '--pt' => '10px', '--pr' => '10px', '--pb' => '10px', '--pl' => '10px', '--mt' => '0px', '--mr' => '0px', '--mb' => '0px', '--ml' => '0px',
            '--w' => '100%', '--min-h' => 'initial', '--grow' => '0', '--shrink' => '1', '--basis' => 'auto', '--wgt-self' => 'initial',
            '--wgt-w' => 'calc((1-var(--wgt-grow))*100%)', '--wgt-grow' => '0', 'display' => 'flex'];
        return ($defaults[$prop] ?? null) === $v || ($prop === '--wgt-w' && $v === '100%') || (in_array($prop, ['--mt', '--mr', '--mb', '--ml'], true) && $v === '0');
    }

    // ---------------------------------------------------------------- widget

    private function widget(array $n): string
    {
        $hide = self::hideClasses($n['hide']);
        $base = $this->pickBp($n['self'], [...self::SELF, ...self::TYPO]);
        $base = $this->merge($base, $this->pickBp($n['wrap'], [self::BOX]), $this->layoutVars($n['self']));

        $html = match ($n['type']) {
            'heading'      => $this->heading($n, $base, $hide),
            'text-editor'  => $this->wrap('div', 'testo', $n['html'], $this->withAlign($base, $n['align']), $hide),
            'image'        => $this->image($n, $base, $hide),
            'card'         => $this->card($n, $base, $hide),
            'icon-list'    => $this->iconList($n, $base, $hide),
            'divider'      => $this->divider($n, $base, $hide),
            'spacer'       => $this->spacer($n, $base, $hide),
            'button'       => $this->button($n, $base, $hide),
            'nav-menu'     => $this->menu($n, $base, $hide),
            'gallery'      => $this->gallery($n, $base, $hide),
            'form'         => $this->form($n, $base, $hide),
            'social-icons' => $this->social($n, $base, $hide),
            default        => $this->wrap('div', 'raw', $n['html'], $base, $hide),
        };
        $this->eager = $this->eagerContext;
        return $html;
    }

    private function heading(array $n, array $base, array $hide): string
    {
        $css = $this->merge($base, $this->pickBp($n['title'], self::TYPO), $this->pickBp($n['title'], [self::BOX]));
        if (isset(self::HEADING_SIZES[$n['size']]) && !isset($css['d']['font-size'])) {
            $css['d']['font-size'] = self::HEADING_SIZES[$n['size']];
        }
        return $this->wrap($n['tag'], 'titolo', $n['html'], $this->withAlign($css, $n['align']), $hide);
    }

    private function image(array $n, array $base, array $hide): string
    {
        $img = $this->imgTag($n['img']);
        if ($n['link']) {
            $img = '<a href="' . htmlspecialchars($n['link']) . '">' . $img . '</a>';
        }
        if ($n['caption'] !== '') {
            $img .= '<figcaption>' . htmlspecialchars($n['caption']) . '</figcaption>';
        }
        return $this->wrap('figure', 'img', $img, $this->withAlign($base, $n['align']), $hide,
            [' img' => $this->pickBp($n['img_style'], ['width', 'max-width', 'height', 'object-fit', 'object-position', 'opacity', '~^border~', 'box-shadow'])]);
    }

    private function card(array $n, array $base, array $hide): string
    {
        $media = $n['img'] ? $this->imgTag($n['img']) : ($n['icon'] ?: '');
        $title = htmlspecialchars($n['title']);
        if ($n['link']) {
            $title = '<a href="' . htmlspecialchars($n['link']) . '">' . $title . '</a>';
        }
        $inner = ($media !== '' ? "<figure class=\"card-media\">$media</figure>" : '')
            . "<div class=\"card-body\"><{$n['title_tag']} class=\"card-title\">$title</{$n['title_tag']}>"
            . ($n['text'] !== '' ? "<div class=\"card-text\">{$n['text']}</div>" : '') . '</div>';
        $css = $this->merge($base, $this->pickBp($n['box_style'], ['text-align']));
        return $this->wrap('div', 'card card--' . $n['position'], $inner, $css, $hide, [
            ' .card-media'     => $this->pickBp($n['media_style'], ['width', 'max-width', '~^margin~']),
            ' .card-media svg' => $this->pickBp($n['icon_style'], ['color', 'fill', 'font-size', 'width', 'height']),
            ' .card-title'     => $this->pickBp($n['title_style'], [...self::TYPO, '~^margin~']),
            ' .card-text'      => $this->pickBp($n['text_style'], self::TYPO),
        ]);
    }

    private function iconList(array $n, array $base, array $hide): string
    {
        $items = '';
        foreach ($n['items'] as $it) {
            $content = ($it['icon'] !== '' ? "<span class=\"li-icon\">{$it['icon']}</span>" : '') . "<span class=\"li-text\">{$it['text']}</span>";
            $items .= '<li>' . ($it['href'] ? '<a href="' . htmlspecialchars($it['href']) . "\">$content</a>" : $content) . '</li>';
        }
        $align = [];
        foreach ($n['align'] as $bp => $a) {
            $align[$bp] = $n['inline'] ? ['justify-content' => ['left' => 'flex-start', 'right' => 'flex-end', 'center' => 'center'][$a] ?? 'flex-start']
                : ['text-align' => $a];
        }
        $iconSize = [];
        foreach ($n['self'] as $bp => $d) {
            if (isset($d['--e-icon-list-icon-size'])) {
                $iconSize[$bp] = ['width' => $d['--e-icon-list-icon-size'], 'height' => $d['--e-icon-list-icon-size']];
            }
        }
        return $this->wrap('ul', 'lista' . ($n['inline'] ? ' lista--inline' : ''), $items, $this->merge($base, $align), $hide, [
            ' .li-icon'          => $this->pickBp($n['icon_style'], ['color', 'fill']),
            ' .li-icon svg'      => $iconSize,
            ' .li-text'          => $this->pickBp($n['text_style'], [...self::TYPO, '~^padding~']),
            ' li:not(:last-child)' => $this->pickBp($n['item_style'], ['~^(padding|margin)~']),
        ]);
    }

    private function divider(array $n, array $base, array $hide): string
    {
        $line = [];
        foreach (['d', 'u', 't', 'm'] as $bp) {
            $s = $n['self'][$bp];
            $l = $this->pick($n['line'][$bp], ['width', '~^margin~']);
            if (isset($s['--divider-border-width']) || isset($s['--divider-color']) || isset($s['--divider-border-style'])) {
                $l['border-top'] = trim(($s['--divider-border-width'] ?? '1px') . ' ' . ($s['--divider-border-style'] ?? 'solid') . ' '
                    . ($s['--divider-color'] ?? '#0c0d0e'));
            }
            $line[$bp] = $l;
        }
        $outer = [];
        foreach ($n['outer'] as $bp => $d) {
            $outer[$bp] = array_filter([
                'padding-top' => $d['padding-block-start'] ?? $d['padding-top'] ?? null,
                'padding-bottom' => $d['padding-block-end'] ?? $d['padding-bottom'] ?? null,
                'text-align' => $d['text-align'] ?? null,
            ]);
        }
        return $this->wrap('div', 'linea', '<span></span>', $this->merge($base, $outer), $hide, [' span' => $line], 'role="separator"');
    }

    private function spacer(array $n, array $base, array $hide): string
    {
        $h = [];
        foreach (['d', 'u', 't', 'm'] as $bp) {
            $v = $n['space'][$bp]['height'] ?? $n['self'][$bp]['--spacer-size'] ?? $n['space'][$bp]['--spacer-size'] ?? null;
            if ($v) {
                $h[$bp] = ['height' => $v];
            }
        }
        return $this->wrap('div', 'spazio', '', $this->merge($base, $h), $hide, [], 'aria-hidden="true"');
    }

    private function button(array $n, array $base, array $hide): string
    {
        $a = '<a class="btn" href="' . htmlspecialchars($n['href'] ?? '#') . '">' . $n['icon'] . '<span>' . htmlspecialchars($n['text']) . '</span></a>';
        return $this->wrap('div', 'pulsante', $a, $this->withAlign($base, $n['align']), $hide,
            [' .btn' => $this->pickBp($n['btn'], [...self::TYPO, self::BOX, 'fill'])]);
    }

    private function menu(array $n, array $base, array $hide): string
    {
        $this->usesMenu = true;
        $bp = ['tablet' => 'menu--t', 'mobile' => 'menu--m', 'none' => ''][$n['breakpoint']] ?? 'menu--t';
        $toggle = $bp === '' ? '' : '<button class="menu-toggle" type="button" aria-expanded="false" aria-label="Apri il menu">'
            . '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18" stroke="currentColor" stroke-width="2" fill="none"/></svg></button>';
        $html = $toggle . '<ul class="menu-list">' . self::menuList($n['items']) . '</ul>';
        $dropdown = [];
        foreach (['t', 'm'] as $b) {
            $dropdown[$b] = $this->pick($n['dropdown_style'][$b] + $n['dropdown_style']['d'], ['background-color', 'background']);
        }
        return $this->wrap('nav', "menu $bp", $html, $base, $hide, [
            ' .menu-list a'       => $this->pickBp($n['item_style'], [...self::TYPO, '~^padding~']),
            ' .menu-list a:hover' => $this->pickBp($n['hover_style'], ['color', 'background-color']),
            ' .menu-toggle'       => $this->pickBp($n['toggle_style'], ['color', 'font-size', 'background-color']),
            ' .menu-list.open'    => $dropdown,
        ], 'aria-label="Menu principale"');
    }

    private static function menuList(array $items): string
    {
        $html = '';
        foreach ($items as $it) {
            $html .= '<li><a href="' . htmlspecialchars($it['href']) . '">' . htmlspecialchars($it['text']) . '</a>'
                . ($it['children'] ? '<ul>' . self::menuList($it['children']) . '</ul>' : '') . '</li>';
        }
        return $html;
    }

    private function gallery(array $n, array $base, array $hide): string
    {
        $s = $n['settings'];
        $lightbox = ($s['link_to'] ?? 'file') === 'file';
        $this->usesLightbox = $this->usesLightbox || $lightbox;
        $vars = [];
        foreach (['d' => '', 't' => '_tablet', 'm' => '_mobile'] as $bp => $suffix) {
            $v = [];
            if (isset($s["columns$suffix"])) {
                $v['--cols'] = (string) $s["columns$suffix"];
            }
            if (isset($s["gap$suffix"]['size']) && $s["gap$suffix"]['size'] !== '') {
                $v['--g-gap'] = $s["gap$suffix"]['size'] . ($s["gap$suffix"]['unit'] ?? 'px');
            }
            $vars[$bp] = $v;
        }
        if (!empty($s['aspect_ratio']) && preg_match('~^(\d+):(\d+)$~', $s['aspect_ratio'], $r)) {
            $vars['d']['--ratio'] = "{$r[1]}/{$r[2]}";
        }
        $items = '';
        foreach ($n['items'] as $it) {
            $img = '<img src="' . htmlspecialchars($it['src'] ?? $it['href']) . '" alt="' . htmlspecialchars($it['alt']) . '" loading="lazy"'
                . ($it['width'] ? ' width="' . (int) $it['width'] . '" height="' . (int) $it['height'] . '"' : '') . '>';
            $items .= $lightbox && $it['href'] ? '<a href="' . htmlspecialchars($it['href']) . "\" data-lightbox>$img</a>" : "<div>$img</div>";
        }
        return $this->wrap('div', 'galleria', $items, $this->merge($base, $vars), $hide);
    }

    private function form(array $n, array $base, array $hide): string
    {
        $html = '';
        foreach ($n['fields'] as $f) {
            $col = in_array($f['col'], [20, 25, 33, 40, 50, 60, 66, 75, 80, 100], true) ? $f['col'] : 100;
            if ($f['type'] === 'submit') {
                $html .= "<div class=\"campo campo--$col\"><button class=\"btn\" type=\"submit\">" . htmlspecialchars($f['text']) . '</button></div>';
                continue;
            }
            $id = htmlspecialchars($f['id'] ?: 'f-' . preg_replace('~\W+~', '-', (string) $f['name']));
            $attrs = 'id="' . $id . '" name="' . htmlspecialchars((string) $f['name']) . '"' . ($f['required'] ? ' required' : '')
                . ($f['placeholder'] !== '' ? ' placeholder="' . htmlspecialchars($f['placeholder']) . '"' : '');
            if ($f['type'] === 'checkbox' || $f['type'] === 'acceptance') {
                $html .= "<div class=\"campo campo--$col campo--check\"><input type=\"checkbox\" $attrs value=\"Sì\"><label for=\"$id\">{$f['label_html']}</label></div>";
                continue;
            }
            $label = $f['label'] !== '' ? '<label for="' . $id . '"' . ($f['label_hidden'] ? ' class="sr-only"' : '') . '>' . htmlspecialchars($f['label']) . '</label>' : '';
            $control = match ($f['type']) {
                'textarea' => "<textarea $attrs rows=\"" . (int) ($f['rows'] ?: 4) . '"></textarea>',
                'select'   => "<select $attrs>" . implode('', array_map(fn($o) => '<option>' . htmlspecialchars($o) . '</option>', $f['options'])) . '</select>',
                default    => '<input type="' . htmlspecialchars($f['type']) . "\" $attrs>",
            };
            $html .= "<div class=\"campo campo--$col\">$label$control</div>";
        }
        $attrs = 'data-ssg-form="' . htmlspecialchars($n['form_key']) . '" method="post"'
            . (($this->formAction ?? $n['action']) ? ' action="' . htmlspecialchars($this->formAction ?? $n['action']) . '"' : '');
        return $this->wrap('form', 'modulo', $html, $base, $hide, [
            ' input:not([type=checkbox]), select, textarea' => $this->pickBp($n['field_style'], [...self::TYPO, self::BOX]),
            ' label'  => $this->pickBp($n['label_style'], self::TYPO),
            ' .btn'   => $this->pickBp($n['button_style'], [...self::TYPO, self::BOX]),
        ], $attrs);
    }

    private function social(array $n, array $base, array $hide): string
    {
        $items = '';
        foreach ($n['items'] as $it) {
            $items .= '<li><a href="' . htmlspecialchars($it['href']) . '" aria-label="' . htmlspecialchars($it['label']) . '" target="_blank" rel="noopener">'
                . $it['icon'] . '</a></li>';
        }
        return $this->wrap('ul', 'social', $items, $this->withAlign($base, $n['align']), $hide,
            [' a' => $this->pickBp($n['icon_style'], ['color', 'background-color', 'font-size', 'padding', '~^border~'])]);
    }

    // ---------------------------------------------------------------- utilità

    /**
     * Elemento radice di un widget. La classe propria viene creata solo se ci sono stili da scrivere.
     *
     * @param array<string, array> $sub  sotto-selettore (' img') → stili per breakpoint
     */
    private function wrap(string $tag, string $kind, string $inner, array $css, array $hide, array $sub = [], string $attrs = ''): string
    {
        $all = ['' => $css] + $sub;
        $has = false;
        foreach ($all as $byBp) {
            foreach ($byBp as $decls) {
                $has = $has || (bool) $decls;
            }
        }
        $name = null;
        if ($has) {
            $name = $this->el(explode(' ', $kind)[0]);
            foreach ($all as $suffix => $byBp) {
                foreach (explode(',', (string) $suffix) as $part) {
                    $this->add(".$name" . ($part === '' ? '' : (str_starts_with(trim($part), ':') ? trim($part) : ' ' . trim($part))), $byBp);
                }
            }
        }
        $classes = implode(' ', array_filter(['w', $kind, $name, ...$hide]));
        return "<$tag class=\"$classes\"" . ($attrs !== '' ? " $attrs" : '') . ">$inner</$tag>";
    }

    private function imgTag(array $img): string
    {
        $loading = $this->eager || $this->eagerContext ? '' : ' loading="lazy"';
        return '<img src="' . htmlspecialchars($img['src']) . '"'
            . ($img['srcset'] ? ' srcset="' . htmlspecialchars($img['srcset']) . '" sizes="(max-width: 767px) 100vw, 50vw"' : '')
            . ' alt="' . htmlspecialchars($img['alt']) . '"'
            . ($img['width'] ? ' width="' . (int) $img['width'] . '" height="' . (int) $img['height'] . '"' : '') . "$loading decoding=\"async\">";
    }

    /** Variabili di layout di un widget dentro un container flex (larghezza, crescita, ordine). */
    private function layoutVars(array $self): array
    {
        $map = ['--container-widget-width' => 'width', '--container-widget-flex-grow' => 'flex-grow',
            '--container-widget-align-self' => 'align-self', '--flex-grow' => 'flex-grow', '--order' => 'order', '--align-self' => 'align-self'];
        $out = ['d' => [], 'u' => [], 't' => [], 'm' => []];
        foreach ($self as $bp => $d) {
            foreach ($map as $from => $to) {
                if (isset($d[$from])) {
                    $out[$bp][$to] = $d[$from];
                }
            }
        }
        return $out;
    }

    private function withAlign(array $css, array $align): array
    {
        foreach ($align as $bp => $a) {
            $css[$bp]['text-align'] ??= $a;
        }
        return $css;
    }

    /** @param string[] $props nomi o regex (che iniziano con ~) */
    private function pick(array $decls, array $props): array
    {
        $out = [];
        foreach ($decls as $k => $v) {
            foreach ($props as $p) {
                if ($p === $k || ($p[0] === '~' && preg_match($p, $k))) {
                    $out[$k] = $this->fontFamily($k, $v);
                    break;
                }
            }
        }
        return $out;
    }

    private function pickBp(array $byBp, array $props): array
    {
        return array_map(fn($d) => $this->pick($d, $props), $byBp + ['d' => [], 'u' => [], 't' => [], 'm' => []]);
    }

    private function merge(array ...$sets): array
    {
        $out = ['d' => [], 'u' => [], 't' => [], 'm' => []];
        foreach ($sets as $set) {
            foreach ($set as $bp => $d) {
                $out[$bp] = array_merge($out[$bp], $d);
            }
        }
        return $out;
    }

    /** "Montserrat", Sans-serif → var(--f-primary) se è uno dei font del tema. */
    private function fontFamily(string $prop, string $value): string
    {
        if ($prop !== 'font-family') {
            return $value;
        }
        foreach ($this->theme->fonts as $role => $f) {
            if (!empty($f['family']) && stripos($value, $f['family']) !== false) {
                return "var(--f-$role)";
            }
        }
        return $value;
    }

    private function add(string $selector, array $byBp): void
    {
        foreach ($byBp as $bp => $decls) {
            if ($decls) {
                $this->rules[$bp][$selector] = array_merge($this->rules[$bp][$selector] ?? [], $decls);
            }
        }
    }

    private function el(string $kind): string
    {
        $this->count[$kind] = ($this->count[$kind] ?? 0) + 1;
        return "{$this->section}__$kind{$this->count[$kind]}";
    }

    private function unique(string $base): string
    {
        $name = $base;
        for ($i = 2; isset($this->taken[$name]); $i++) {
            $name = "$base-$i";
        }
        $this->taken[$name] = true;
        return $name;
    }

    private static function hideClasses(array $hide): array
    {
        return array_map(fn($bp) => "hide-$bp", array_keys(array_filter($hide)));
    }

    private static function firstHeading(array $node): string
    {
        foreach ($node['children'] ?? [] as $c) {
            if ($c['kind'] === 'widget' && $c['type'] === 'heading') {
                return strip_tags($c['html']);
            }
            if ($c['kind'] === 'box' && ($h = self::firstHeading($c)) !== '') {
                return $h;
            }
        }
        return '';
    }

    /** Slug breve (max 3 parole) per i nomi di sezione. */
    public static function slug(string $text): string
    {
        $text = strtr(mb_strtolower(html_entity_decode($text)), ['à' => 'a', 'è' => 'e', 'é' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u']);
        $words = array_slice(array_filter(preg_split('~[^a-z0-9]+~', $text)), 0, 3);
        return implode('-', $words);
    }

    public function resetForPage(): void
    {
        $this->eager = true;
    }
}

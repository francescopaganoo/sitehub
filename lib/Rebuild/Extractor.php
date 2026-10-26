<?php
declare(strict_types=1);

namespace Rebuild;

use Dom;

/**
 * Elementor (HTML + CSS) → modello del sito: box (sezioni/colonne) e widget, con gli stili
 * per breakpoint: 'd' sempre, 'u' da 768px in su, 't' tablet (≤1024px), 'm' mobile (≤767px).
 *
 * Il modello non contiene nulla di Elementor: lo usa il Renderer per scrivere HTML e CSS puliti.
 */
final class Extractor
{
    /** @var array<string, array{0:string, 1:string, 2:array}[]> id elemento → [suffisso selettore, breakpoint, dichiarazioni] */
    private array $index = [];
    public array $warnings = [];
    /** Tipo del widget in elaborazione: i suoi stili partono dai predefiniti del tipo (font e colori del kit). */
    private ?string $widgetType = null;

    /** Variabili dei container Elementor → variabili del nostro layout (stesso modello flex). */
    private const BOX_VARS = [
        '--flex-direction' => '--dir', '--flex-wrap' => '--wrap', '--justify-content' => '--justify',
        '--align-items' => '--align', '--min-height' => '--min-h', '--width' => '--w', '--content-width' => '--content',
        '--padding-top' => '--pt', '--padding-right' => '--pr', '--padding-bottom' => '--pb', '--padding-left' => '--pl',
        '--margin-top' => '--mt', '--margin-right' => '--mr', '--margin-bottom' => '--mb', '--margin-left' => '--ml',
        '--container-widget-width' => '--wgt-w', '--container-widget-flex-grow' => '--wgt-grow',
        '--container-widget-align-self' => '--wgt-self', '--flex-grow' => '--grow', '--flex-shrink' => '--shrink',
        '--flex-basis' => '--basis', '--order' => 'order', '--display' => 'display', '--border-radius' => 'border-radius',
        '--overlay-opacity' => '--overlay-opacity', '--align-self' => 'align-self',
    ];

    /** Widget che si possono copiare così come sono (contenuto già pulito o gestito da altri moduli). */
    private const PASSTHROUGH = ['html', 'google_maps', 'video', 'shortcode', 'wp-widget-text'];

    public function __construct(private Css $css, private Icons $icons)
    {
        foreach ($css->rules as $r) {
            $bp = self::breakpoint($r['media']);
            if ($bp === null) {
                continue;
            }
            foreach ($r['selectors'] as $sel) {
                if (preg_match('~\.elementor-element-([a-f0-9]{6,8})((?:(?!elementor-element-[a-f0-9]).)*)$~', $sel, $m)) {
                    // classi composte sullo stesso elemento (".elementor-element-x.elementor-element") = l'elemento stesso
                    $suffix = preg_replace('~^(\.[\w-]+)+(?=$|[\s>:])~', '', $m[2]);
                    $this->index[$m[1]][] = [trim($suffix), $bp, $r['decls']];
                } elseif (preg_match('~^\.elementor-widget-([\w-]+)((?:\s|>).*)?$~', $sel, $m)) {
                    // predefiniti per tipo di widget, es. .elementor-widget-text-editor { font-family: var(--e-global-…) }
                    $this->index['w:' . $m[1]][] = [trim($m[2] ?? ''), $bp, $r['decls']];
                }
            }
        }
    }

    /** @return ?string d | u | t | m (null = media query non pertinente: hover, stampa, animazioni…) */
    public static function breakpoint(?string $media): ?string
    {
        if ($media === null) {
            return 'd';
        }
        if (preg_match('~max-width:\s*(\d+)px~', $media, $m) && !str_contains($media, 'min-width')) {
            return (int) $m[1] <= 767 ? 'm' : ((int) $m[1] <= 1024 ? 't' : null);
        }
        if (preg_match('~^\(?min-width:\s*(\d+)px\)?$~', trim($media), $m)) {
            return (int) $m[1] <= 768 ? 'u' : null;
        }
        return null;
    }

    /**
     * Dichiarazioni per breakpoint delle regole dell'elemento il cui suffisso soddisfa $match.
     *
     * @param string|callable(string):bool $match suffisso esatto ('' = l'elemento stesso) o funzione
     * @return array{d:array, u:array, t:array, m:array}
     */
    public function style(string $id, string|callable $match): array
    {
        $out = ['d' => [], 'u' => [], 't' => [], 'm' => []];
        $rules = $this->index[$id] ?? [];
        if ($this->widgetType !== null) {
            $rules = [...$this->index['w:' . $this->widgetType] ?? [], ...$rules];
        }
        foreach ($rules as [$suffix, $bp, $decls]) {
            $ok = is_string($match) ? $suffix === $match : $match($suffix);
            if ($ok) {
                $out[$bp] = array_merge($out[$bp], $decls);
            }
        }
        return $out;
    }

    /** Regole il cui suffisso termina con $tail (es. '.elementor-heading-title'), escluse :hover e simili. */
    public function styleEnding(string $id, string ...$tails): array
    {
        return $this->style($id, function (string $s) use ($tails) {
            foreach ($tails as $t) {
                if ($s === $t || str_ends_with($s, ' ' . $t) || str_ends_with($s, '>' . $t) || str_ends_with($s, '> ' . $t)) {
                    return true;
                }
            }
            return false;
        });
    }

    // ---------------------------------------------------------------- albero

    /** @return array[] nodi figli (box e widget) di un documento Elementor o di un contenitore */
    public function children(Dom\Element $el): array
    {
        $out = [];
        for ($c = $el->firstElementChild; $c; $c = $c->nextElementSibling) {
            $type = $c->getAttribute('data-element_type');
            if (in_array($type, ['container', 'section', 'column'], true)) {
                $out[] = $this->box($c, $type);
            } elseif ($type === 'widget' || str_starts_with((string) $type, 'widget')) {
                $node = $this->widget($c);
                if ($node) {
                    $out[] = $node;
                }
            } else {
                array_push($out, ...$this->children($c));
            }
        }
        return $out;
    }

    private function box(Dom\Element $el, string $type): array
    {
        $this->widgetType = null;
        $id = $el->getAttribute('data-id');
        $cls = ' ' . ($el->getAttribute('class') ?? '') . ' ';
        $settings = json_decode(html_entity_decode($el->getAttribute('data-settings') ?? ''), true) ?: [];
        $self = $this->style($id, '');

        $vars = ['d' => [], 'u' => [], 't' => [], 'm' => []];
        $gap = [];
        foreach ($self as $bp => $decls) {
            foreach ($decls as $k => $v) {
                $v = self::renameVars($v);
                // su mobile il layout legge --wrap-m (vedi base.css): così la regola desktop non lo sovrascrive
                if ($k === '--flex-wrap-mobile' || ($k === '--flex-wrap' && $bp === 'm')) {
                    $vars[$bp]['--wrap-m'] = $v;
                } elseif (isset(self::BOX_VARS[$k])) {
                    $vars[$bp][self::BOX_VARS[$k]] = $v;
                } elseif ($k === '--gap' || $k === '--row-gap' || $k === '--column-gap') {
                    $gap[$bp][$k] = $v;
                } elseif (preg_match('~^(border|box-shadow)~', $k)) {
                    $vars[$bp][$k] = $v;
                }
            }
            if (isset($gap[$bp])) {
                $g = $gap[$bp];
                $vars[$bp]['--gap'] = isset($g['--row-gap'], $g['--column-gap']) ? "{$g['--row-gap']} {$g['--column-gap']}" : ($g['--gap'] ?? reset($g));
            }
        }

        $legacy = $type !== 'container';
        if ($legacy) {
            $this->legacyBox($id, $type, $cls, $vars);
        }

        $bgMatch = fn(string $s) => str_starts_with($s, ':not(.elementor-motion-effects-element-type-background)') || ($legacy && $s === '');
        $bg = $this->filterBp($this->style($id, $bgMatch), '~^background~');
        $overlay = $this->filterBp($this->style($id, fn($s) => $s === '::before' || str_contains($s, '> .elementor-background-overlay')),
            '~^(background|opacity|mix-blend-mode)~');

        $slides = [];
        foreach ($settings['background_slideshow_gallery'] ?? [] as $img) {
            if (!empty($img['url'])) {
                $slides[] = $img['url'];
            }
        }

        return [
            'kind'     => 'box',
            'id'       => $id,
            'anchor'   => $el->getAttribute('id') ?: null,
            'boxed'    => str_contains($cls, ' e-con-boxed ') || ($type === 'section' && str_contains($cls, ' elementor-section-boxed ')),
            'vars'     => $vars,
            'bg'       => $bg,
            'overlay'  => $overlay,
            'slides'   => $slides ? ['urls' => $slides, 'duration' => (int) ($settings['background_slideshow_slide_duration'] ?? 5000),
                'kenburns' => ($settings['background_slideshow_ken_burns'] ?? '') === 'yes'] : null,
            'sticky'   => in_array($settings['sticky'] ?? '', ['top'], true),
            'hide'     => self::hidden($cls),
            'children' => $this->children($el),
        ];
    }

    /** Sezioni/colonne del vecchio Elementor: proprietà CSS normali invece delle variabili. */
    private function legacyBox(string $id, string $type, string $cls, array &$vars): void
    {
        $self = $this->style($id, '');
        if ($type === 'section') {
            $vars['d'] += ['--dir' => 'row', '--gap' => '0px', '--pt' => '0px', '--pr' => '0px', '--pb' => '0px', '--pl' => '0px'];
            $inner = $this->style($id, '> .elementor-container');
            foreach ($inner as $bp => $d) {
                if (isset($d['max-width'])) {
                    $vars[$bp]['--content'] = $d['max-width'];
                }
                if (isset($d['min-height'])) {
                    $vars[$bp]['--min-h'] = $d['min-height'];
                }
            }
        } else {
            preg_match('~elementor-col-(\d+)~', $cls, $col);
            $vars['d'] += ['--w' => isset($col[1]) ? $col[1] . '%' : '100%', '--gap' => '0px'];
            $wrap = $this->style($id, '> .elementor-widget-wrap');
            foreach ($wrap as $bp => $d) {
                foreach (self::box4($d['padding'] ?? null) as $k => $v) {
                    $vars[$bp][$k] = $v;
                }
            }
        }
        foreach ($self as $bp => $d) {
            if (isset($d['width'])) {
                $vars[$bp]['--w'] = $d['width'];
            }
            foreach (self::box4($d['padding'] ?? null) as $k => $v) {
                $vars[$bp][$k] = $v;
            }
            foreach (self::box4($d['margin'] ?? null, 'm') as $k => $v) {
                $vars[$bp][$k] = $v;
            }
        }
    }

    // ---------------------------------------------------------------- widget

    private function widget(Dom\Element $el): ?array
    {
        $type = explode('.', $el->getAttribute('data-widget_type') ?? 'unknown')[0];
        $id = $el->getAttribute('data-id');
        $this->widgetType = $type;
        $cls = ' ' . ($el->getAttribute('class') ?? '') . ' ';
        $wrap = $this->firstChildWithClass($el, 'elementor-widget-container') ?? $el;

        $node = [
            'kind' => 'widget', 'type' => $type, 'id' => $id, 'hide' => self::hidden($cls),
            'self' => $this->style($id, ''),
            'wrap' => $this->style($id, '> .elementor-widget-container'),
            'align' => self::alignClass($cls),
        ];

        switch ($type) {
            case 'heading':
                $t = $wrap->querySelector('.elementor-heading-title');
                if (!$t) {
                    return null;
                }
                preg_match('~elementor-size-(\w+)~', $t->getAttribute('class') ?? '', $size);
                return $node + ['tag' => $t->localName, 'html' => self::inner($t), 'size' => $size[1] ?? 'default',
                    'title' => $this->styleEnding($id, '.elementor-heading-title')];

            case 'text-editor':
                return $node + ['html' => self::clean($wrap)];

            case 'image':
            case 'theme-site-logo':
                $img = $wrap->querySelector('img');
                if (!$img) {
                    return null;
                }
                return array_merge($node, ['type' => 'image', 'img' => self::img($img), 'link' => $img->closest('a')?->getAttribute('href'),
                    'caption' => trim($wrap->querySelector('figcaption')?->textContent ?? ''),
                    'img_style' => $this->styleEnding($id, 'img')]);

            case 'image-box':
            case 'icon-box':
                $img = $wrap->querySelector('.elementor-image-box-img img');
                $icon = $wrap->querySelector('.elementor-icon i, .elementor-icon svg');
                $title = $wrap->querySelector('.elementor-image-box-title, .elementor-icon-box-title');
                preg_match('~elementor-position-(\w+)~', $cls, $pos);
                $prefix = $type === 'image-box' ? 'image' : 'icon';
                // le regole per le altre posizioni dell'immagine (sinistra/destra/sopra) non valgono per questo widget
                $position = $pos[1] ?? 'top';
                $mediaStyle = $this->style($id, fn($s) => (str_ends_with($s, ".elementor-$prefix-box-img") || str_ends_with($s, '.elementor-icon'))
                    && (!str_contains($s, 'elementor-position-') || str_contains($s, "elementor-position-$position")));
                return array_merge($node, [
                    'type' => 'card', 'position' => $position,
                    'img' => $img ? self::img($img) : null, 'icon' => $icon ? $this->icon($icon) : null,
                    'title_tag' => $title?->localName ?? 'h3', 'title' => trim($title?->textContent ?? ''),
                    'link' => $wrap->querySelector('a')?->getAttribute('href'),
                    'text' => self::clean($wrap->querySelector(".elementor-$prefix-box-description") ?? $el->ownerDocument->createElement('p')),
                    'media_style' => $mediaStyle,
                    'title_style' => $this->styleEnding($id, ".elementor-$prefix-box-title", ".elementor-$prefix-box-title a"),
                    'text_style' => $this->styleEnding($id, ".elementor-$prefix-box-description"),
                    'box_style' => $this->styleEnding($id, ".elementor-$prefix-box-wrapper"),
                    'icon_style' => $this->styleEnding($id, '.elementor-icon', '.elementor-icon i', '.elementor-icon svg'),
                ]);

            case 'icon-list':
                $items = [];
                foreach ($wrap->querySelectorAll('.elementor-icon-list-item') as $li) {
                    $icon = $li->querySelector('.elementor-icon-list-icon i, .elementor-icon-list-icon svg');
                    $items[] = ['href' => $li->querySelector('a')?->getAttribute('href'), 'icon' => $icon ? $this->icon($icon) : '',
                        'text' => self::inner($li->querySelector('.elementor-icon-list-text') ?? $li)];
                }
                return $node + ['items' => $items, 'inline' => str_contains($cls, 'elementor-icon-list--layout-inline'),
                    'icon_style' => $this->styleEnding($id, '.elementor-icon-list-icon i', '.elementor-icon-list-icon svg', '.elementor-icon-list-icon'),
                    'text_style' => $this->styleEnding($id, '.elementor-icon-list-text', '.elementor-icon-list-item > a',
                        '.elementor-icon-list-item > .elementor-icon-list-text'),
                    'item_style' => $this->style($id, fn($s) => str_contains($s, '.elementor-icon-list-item:not(:last-child)')),
                ];

            case 'divider':
                return $node + ['line' => $this->styleEnding($id, '.elementor-divider-separator'),
                    'outer' => $this->styleEnding($id, '.elementor-divider')];

            case 'spacer':
                return $node + ['space' => $this->styleEnding($id, '.elementor-spacer-inner')];

            case 'button':
                $a = $wrap->querySelector('a.elementor-button, .elementor-button');
                if (!$a) {
                    return null;
                }
                $icon = $a->querySelector('i, svg');
                return $node + ['href' => $a->getAttribute('href'), 'text' => trim($a->querySelector('.elementor-button-text')?->textContent ?? $a->textContent),
                    'icon' => $icon ? $this->icon($icon) : '', 'btn' => $this->styleEnding($id, '.elementor-button')];

            case 'nav-menu':
                $ul = $wrap->querySelector('nav.elementor-nav-menu--main > ul') ?? $wrap->querySelector('ul.elementor-nav-menu');
                if (!$ul) {
                    return null;
                }
                preg_match('~elementor-nav-menu--dropdown-(\w+)~', $cls, $bp);
                return $node + ['items' => self::menuItems($ul), 'breakpoint' => $bp[1] ?? 'tablet',
                    'item_style' => $this->styleEnding($id, '.elementor-nav-menu--main .elementor-item', '.elementor-nav-menu .elementor-item'),
                    'hover_style' => $this->style($id, fn($s) => str_contains($s, '.elementor-item:hover')),
                    'dropdown_style' => $this->styleEnding($id, '.elementor-nav-menu--dropdown'),
                    'dropdown_link' => $this->styleEnding($id, '.elementor-nav-menu--dropdown a', '.elementor-menu-toggle'),
                    'toggle_style' => $this->styleEnding($id, 'div.elementor-menu-toggle', '.elementor-menu-toggle')];

            case 'gallery':
                $settings = json_decode(html_entity_decode($el->getAttribute('data-settings') ?? ''), true) ?: [];
                $items = [];
                foreach ($wrap->querySelectorAll('.e-gallery-item') as $a) {
                    $imgEl = $a->querySelector('.e-gallery-image');
                    $items[] = ['href' => $a->getAttribute('href'), 'src' => $imgEl?->getAttribute('data-thumbnail'),
                        'width' => $imgEl?->getAttribute('data-width'), 'height' => $imgEl?->getAttribute('data-height'),
                        'alt' => $imgEl?->getAttribute('aria-label') ?? ''];
                }
                return $node + ['items' => $items, 'settings' => $settings];

            case 'form':
                return $node + $this->form($el, $wrap, $id);

            case 'social-icons':
                $items = [];
                foreach ($wrap->querySelectorAll('a.elementor-social-icon') as $a) {
                    $icon = $a->querySelector('i, svg');
                    $items[] = ['href' => $a->getAttribute('href'), 'label' => trim($a->querySelector('.elementor-screen-only')?->textContent ?? ''),
                        'icon' => $icon ? $this->icon($icon) : ''];
                }
                return $node + ['items' => $items, 'icon_style' => $this->styleEnding($id, '.elementor-social-icon', '.elementor-social-icon svg')];
        }

        if (!in_array($type, self::PASSTHROUGH, true)) {
            $this->warnings[] = "widget «{$type}» non convertito: copiato com'era (id $id)";
        }
        return array_merge($node, ['type' => 'raw', 'widget' => $type, 'html' => self::inner($wrap)]);
    }

    private function form(Dom\Element $el, Dom\Element $wrap, string $id): array
    {
        $f = $wrap->querySelector('form');
        $fields = [];
        foreach ($wrap->querySelectorAll('.elementor-field-group') as $g) {
            $gcls = $g->getAttribute('class') ?? '';
            preg_match('~elementor-col-(\d+)~', $gcls, $col);
            if (str_contains($gcls, 'elementor-field-type-submit')) {
                $btn = $g->querySelector('button');
                $fields[] = ['type' => 'submit', 'text' => trim($btn?->textContent ?? 'Invia'), 'col' => (int) ($col[1] ?? 100)];
                continue;
            }
            $input = $g->querySelector('input, textarea, select');
            if (!$input || $input->getAttribute('type') === 'hidden') {
                continue;
            }
            // campi nascosti via CSS = honeypot del plugin: non si ricostruiscono
            if (preg_match('~display:\s*none~', $input->getAttribute('style') ?? '') || str_contains($gcls, 'elementor-field-type-honeypot')) {
                continue;
            }
            $label = $g->querySelector('label');
            $type = $input->localName === 'input' ? ($input->getAttribute('type') ?? 'text') : $input->localName;
            $field = [
                'type' => $type, 'name' => $input->getAttribute('name'), 'id' => $input->getAttribute('id'),
                'label' => $label ? trim(preg_replace('~\s+~', ' ', $label->textContent)) : '',
                'label_hidden' => !$label || str_contains($label->getAttribute('class') ?? '', 'elementor-screen-only'),
                'label_html' => $label ? self::inner($label) : '',
                'placeholder' => $input->getAttribute('placeholder') ?? '', 'required' => $input->hasAttribute('required'),
                'col' => (int) ($col[1] ?? 100), 'rows' => $input->getAttribute('rows'),
                'options' => array_map(fn($o) => $o->textContent, iterator_to_array($input->querySelectorAll('option'))),
            ];
            if ($type === 'checkbox') {
                // accettazione privacy: l'etichetta (con link) è nel label accanto alla casella
                $field['label_html'] = self::inner($g->querySelector('label[for="' . $input->getAttribute('id') . '"]') ?? $label ?? $g);
                $field['label_hidden'] = false;
            }
            $fields[] = $field;
        }
        return [
            'form_key' => $f?->getAttribute('data-ssg-form') ?? 'contatti', 'action' => $f?->getAttribute('action'),
            'name' => $f?->getAttribute('name') ?? '', 'fields' => $fields,
            'field_style' => $this->style($id, fn($s) => str_contains($s, '.elementor-field-group') && str_contains($s, '.elementor-field')
                && !str_contains($s, 'placeholder') && !str_contains($s, 'label')),
            'label_style' => $this->style($id, fn($s) => str_contains($s, '.elementor-field-group > label') || str_ends_with($s, '.elementor-field-label')),
            'button_style' => $this->styleEnding($id, '.elementor-button', '.e-form__buttons__wrapper__button-next', 'button'),
            'group_style' => $this->styleEnding($id, '.elementor-field-group'),
        ];
    }

    // ---------------------------------------------------------------- utilità

    private function icon(Dom\Element $el): string
    {
        if ($el->localName === 'svg') {
            $el->setAttribute('class', 'icon');
            $el->setAttribute('aria-hidden', 'true');
            return $el->ownerDocument->saveHtml($el);
        }
        return $this->icons->svg($el->getAttribute('class') ?? '');
    }

    private static function menuItems(Dom\Element $ul): array
    {
        $items = [];
        for ($li = $ul->firstElementChild; $li; $li = $li->nextElementSibling) {
            $a = $li->querySelector('a');
            if (!$a) {
                continue;
            }
            $sub = null;
            for ($c = $li->firstElementChild; $c; $c = $c->nextElementSibling) {
                if ($c->localName === 'ul') {
                    $sub = self::menuItems($c);
                }
            }
            $items[] = ['href' => $a->getAttribute('href'), 'text' => trim($a->textContent), 'children' => $sub];
        }
        return $items;
    }

    /** Immagine: si tiene la variante più grande fino a 1600px e una media per i telefoni. */
    public static function img(Dom\Element $img): array
    {
        $candidates = [];
        foreach (array_filter(array_map('trim', explode(',', $img->getAttribute('srcset') ?? ''))) as $c) {
            $p = preg_split('~\s+~', $c);
            if (isset($p[1]) && str_ends_with($p[1], 'w')) {
                $candidates[(int) $p[1]] = $p[0];
            }
        }
        ksort($candidates);
        $src = $img->getAttribute('src') ?? '';
        $best = $src;
        $bestW = (int) ($img->getAttribute('width') ?? 0);
        foreach ($candidates as $w => $u) {
            if ($w <= 1600) {
                [$best, $bestW] = [$u, $w];
            }
        }
        $srcset = [];
        foreach ($candidates as $w => $u) {
            if ($w >= 600 && $w <= 900 && $w < $bestW) {
                $srcset = ["$u {$w}w", "$best {$bestW}w"];
                break;
            }
        }
        return ['src' => $best, 'srcset' => implode(', ', $srcset), 'alt' => $img->getAttribute('alt') ?? '',
            'width' => $img->getAttribute('width'), 'height' => $img->getAttribute('height')];
    }

    /** HTML interno senza classi/attributi di Elementor (testi, liste, link restano). */
    public static function clean(Dom\Element $el): string
    {
        $copy = $el->cloneNode(true);
        foreach ($copy->querySelectorAll('*') as $n) {
            foreach (iterator_to_array($n->attributes) as $a) {
                if (str_starts_with($a->name, 'data-') && !str_starts_with($a->name, 'data-ssg')) {
                    $n->removeAttribute($a->name);
                } elseif ($a->name === 'class') {
                    $keep = array_filter(explode(' ', $a->value), fn($c) => $c !== '' && !str_starts_with($c, 'elementor') && !str_starts_with($c, 'e-'));
                    $keep ? $n->setAttribute('class', implode(' ', $keep)) : $n->removeAttribute('class');
                }
            }
        }
        return trim(self::inner($copy));
    }

    public static function inner(Dom\Element $el): string
    {
        return trim($el->innerHTML);
    }

    private function firstChildWithClass(Dom\Element $el, string $class): ?Dom\Element
    {
        for ($c = $el->firstElementChild; $c; $c = $c->nextElementSibling) {
            if (str_contains(' ' . ($c->getAttribute('class') ?? '') . ' ', " $class ")) {
                return $c;
            }
        }
        return null;
    }

    /** elementor-hidden-mobile ecc. → ['m' => true, …] */
    private static function hidden(string $cls): array
    {
        $out = [];
        foreach (['desktop' => 'd', 'tablet' => 't', 'mobile' => 'm'] as $name => $bp) {
            if (str_contains($cls, "elementor-hidden-$name ")) {
                $out[$bp] = true;
            }
        }
        return $out;
    }

    /** elementor-align-center / elementor-mobile-align-left… → ['d' => 'center', 'm' => 'left'] */
    private static function alignClass(string $cls): array
    {
        $out = [];
        foreach (['' => 'd', 'tablet-' => 't', 'mobile-' => 'm'] as $prefix => $bp) {
            if (preg_match("~ elementor-{$prefix}align-(left|center|right|justify|start|end) ~", $cls, $m)) {
                $out[$bp] = ['start' => 'left', 'end' => 'right'][$m[1]] ?? $m[1];
            }
        }
        return $out;
    }

    private function filterBp(array $byBp, string $propRe): array
    {
        foreach ($byBp as $bp => $d) {
            $byBp[$bp] = array_filter($d, fn($k) => (bool) preg_match($propRe, $k), ARRAY_FILTER_USE_KEY);
        }
        return $byBp;
    }

    /** var(--container-widget-flex-grow) → var(--wgt-grow): i valori di Elementor citano le sue variabili */
    private static function renameVars(string $v): string
    {
        return preg_replace_callback('~var\(\s*(--[\w-]+)\s*\)~', fn($m) => 'var(' . (self::BOX_VARS[$m[1]] ?? $m[1]) . ')', $v);
    }

    /** "30px 0 10px" → ['--pt' => '30px', '--pr' => '0', '--pb' => '10px', '--pl' => '0'] */
    private static function box4(?string $value, string $prefix = 'p'): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        $v = preg_split('~\s+~', trim($value));
        [$t, $r, $b, $l] = match (count($v)) {
            1 => [$v[0], $v[0], $v[0], $v[0]],
            2 => [$v[0], $v[1], $v[0], $v[1]],
            3 => [$v[0], $v[1], $v[2], $v[1]],
            default => [$v[0], $v[1], $v[2], $v[3]],
        };
        return ["--{$prefix}t" => $t, "--{$prefix}r" => $r, "--{$prefix}b" => $b, "--{$prefix}l" => $l];
    }
}

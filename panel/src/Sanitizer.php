<?php
declare(strict_types=1);

namespace Panel;

use Dom;

/**
 * Pulizia dell'HTML degli articoli: arriva da editor, clienti e gestionale, finisce su un sito pubblico.
 * Lista di tag/attributi ammessi; i tag sconosciuti vengono "srotolati" (resta il testo),
 * quelli pericolosi rimossi del tutto.
 */
final class Sanitizer
{
    private const ALLOWED = [
        'p' => [], 'br' => [], 'hr' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
        'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [], 'sub' => [], 'sup' => [], 'mark' => [],
        'a' => ['href', 'title', 'target', 'rel'], 'ul' => [], 'ol' => ['start'], 'li' => [],
        'blockquote' => ['cite'], 'pre' => [], 'code' => [], 'span' => [], 'div' => [],
        'figure' => [], 'figcaption' => [], 'img' => ['src', 'alt', 'width', 'height', 'loading', 'title', 'data-media'],
        'table' => [], 'thead' => [], 'tbody' => [], 'tfoot' => [], 'tr' => [], 'th' => ['colspan', 'rowspan', 'scope'],
        'td' => ['colspan', 'rowspan'], 'caption' => [],
    ];

    /** Attributi ammessi su tutti i tag ammessi. */
    private const GLOBAL_ATTRS = ['class', 'id', 'style'];

    /** Rimossi col loro contenuto. */
    private const DROP = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea',
        'select', 'template', 'svg', 'math', 'link', 'meta', 'base', 'noscript', 'frame', 'frameset'];

    public static function html(string $html): string
    {
        $doc = Dom\HTMLDocument::createFromString('<!doctype html><body>' . $html, LIBXML_NOERROR, 'UTF-8');
        self::clean($doc->body);
        return trim($doc->body->innerHTML);
    }

    private static function clean(Dom\Node $node): void
    {
        for ($child = $node->firstChild; $child; $child = $next) {
            $next = $child->nextSibling;
            if ($child instanceof Dom\Comment) {
                $child->remove();
                continue;
            }
            if (!$child instanceof Dom\Element) {
                continue;
            }
            $tag = $child->localName;
            if (in_array($tag, self::DROP, true)) {
                $child->remove();
                continue;
            }
            self::clean($child);
            if (!isset(self::ALLOWED[$tag])) {
                // tag non ammesso: resta il contenuto
                while ($child->firstChild) {
                    $child->before($child->firstChild);
                }
                $child->remove();
                continue;
            }
            foreach (iterator_to_array($child->attributes) as $attr) {
                $name = $attr->name;
                $ok = in_array($name, self::GLOBAL_ATTRS, true) || in_array($name, self::ALLOWED[$tag], true);
                if ($name === 'style') {
                    // allineamenti e colori dell'editor sì, trucchi CSS no
                    $ok = !preg_match('~expression|javascript:|@import|url\s*\(~i', $attr->value);
                }
                if (!$ok || (in_array($name, ['href', 'src', 'cite'], true) && !self::safeUrl($attr->value))) {
                    $child->removeAttribute($name);
                }
            }
            if ($tag === 'a' && $child->getAttribute('target') === '_blank') {
                $child->setAttribute('rel', 'noopener');
            }
        }
    }

    private static function safeUrl(string $url): bool
    {
        $url = trim($url);
        return $url === '' || !preg_match('~^[a-z][a-z0-9+.-]*:~i', $url) || preg_match('~^(https?|mailto|tel):~i', $url);
    }
}

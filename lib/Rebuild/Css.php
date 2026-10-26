<?php
declare(strict_types=1);

namespace Rebuild;

/**
 * Parser CSS minimale: regole con selettori, dichiarazioni e @media di appartenenza.
 * Basta per leggere il CSS di Elementor (niente cascata completa: non serve).
 */
final class Css
{
    /** @var array{media:?string, selectors:string[], decls:array<string,string>}[] */
    public array $rules = [];

    public static function parse(string $css): self
    {
        $self = new self();
        $css = preg_replace('~/\*.*?\*/~s', '', $css);
        $self->block($css, null);
        return $self;
    }

    private function block(string $css, ?string $media): void
    {
        $len = strlen($css);
        $i = 0;
        while ($i < $len) {
            $open = strpos($css, '{', $i);
            if ($open === false) {
                break;
            }
            $prelude = trim(substr($css, $i, $open - $i));
            $close = self::matchingBrace($css, $open);
            $body = substr($css, $open + 1, $close - $open - 1);
            $i = $close + 1;

            // eventuali ";" di @charset/@import finiti nel prelude
            if (str_contains($prelude, ';')) {
                $prelude = trim(substr($prelude, strrpos($prelude, ';') + 1));
            }
            if (stripos($prelude, '@media') === 0) {
                $this->block($body, trim(substr($prelude, 6)));
            } elseif ($prelude !== '' && $prelude[0] !== '@') {
                $this->rules[] = ['media' => $media, 'selectors' => self::splitTop($prelude, ','), 'decls' => self::decls($body)];
            }
            // @font-face, @keyframes, @supports…: ignorati
        }
    }

    /** "a:1; b:url(data:x;y)" → ['a' => '1', 'b' => 'url(data:x;y)'] */
    public static function decls(string $body): array
    {
        $out = [];
        foreach (self::splitTop($body, ';') as $d) {
            $p = strpos($d, ':');
            if ($p !== false) {
                $out[strtolower(trim(substr($d, 0, $p)))] = trim(str_replace('!important', '', substr($d, $p + 1)));
            }
        }
        return $out;
    }

    /** Divide su $sep ignorando parentesi e virgolette. @return string[] */
    public static function splitTop(string $s, string $sep): array
    {
        $parts = [];
        $depth = 0;
        $quote = null;
        $cur = '';
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $c = $s[$i];
            if ($quote) {
                $quote = $c === $quote ? null : $quote;
            } elseif ($c === '"' || $c === "'") {
                $quote = $c;
            } elseif ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
            } elseif ($c === $sep && $depth === 0) {
                $parts[] = trim($cur);
                $cur = '';
                continue;
            }
            $cur .= $c;
        }
        if (trim($cur) !== '') {
            $parts[] = trim($cur);
        }
        return $parts;
    }

    private static function matchingBrace(string $s, int $open): int
    {
        $depth = 0;
        for ($i = $open, $n = strlen($s); $i < $n; $i++) {
            if ($s[$i] === '{') {
                $depth++;
            } elseif ($s[$i] === '}' && --$depth === 0) {
                return $i;
            }
        }
        return $n - 1;
    }

    /**
     * Dichiarazioni (solo desktop, fuori da @media) dei selettori che soddisfano $match.
     * Le regole successive sovrascrivono le precedenti, come nel browser a parità di specificità.
     *
     * @param callable(string):bool $match
     */
    public function collect(callable $match, ?string $media = null): array
    {
        $out = [];
        foreach ($this->rules as $r) {
            if ($r['media'] !== $media) {
                continue;
            }
            foreach ($r['selectors'] as $sel) {
                if ($match($sel)) {
                    $out = array_merge($out, $r['decls']);
                    break;
                }
            }
        }
        return $out;
    }

    /** Media query presenti, es. "(max-width:1024px)". @return string[] */
    public function medias(): array
    {
        return array_values(array_unique(array_filter(array_column($this->rules, 'media'))));
    }
}

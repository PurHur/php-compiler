<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * NestedJIT-safe href= query rewrite for thin AOT (#31099 / re-#27566).
 *
 * Subset of {@see UrlScannerEx} / php-src url_scanner_ex.re for `a=href`:
 * inject `?url_app` / `&url_app` into each `href="…"` value.
 * Quotemeta-style strlen/substr/strpos + advanceIdx (#30858 / #30859).
 */
final class VmUrlRewriterHrefApply
{
    private const NEEDLE = 'href="';

    private const NEEDLE_LEN = 6;

    public static function apply(string $content, string $urlApp): string
    {
        if ('' === $urlApp) {
            return $content;
        }
        $len = \strlen($content);
        if (0 === $len) {
            return $content;
        }
        $out = '';
        $pos = 0;
        while ($pos < $len) {
            $rest = \substr($content, $pos);
            $hit = \strpos($rest, self::NEEDLE);
            if (false === $hit) {
                return $out.$rest;
            }
            $hrefAt = $pos + $hit;
            $afterNeedle = $hrefAt + self::NEEDLE_LEN;
            $out = $out.\substr($content, $pos, $afterNeedle - $pos);
            if ($afterNeedle >= $len) {
                return $out;
            }
            $quoteRel = \strpos(\substr($content, $afterNeedle), '"');
            if (false === $quoteRel) {
                return $out.\substr($content, $afterNeedle);
            }
            $url = \substr($content, $afterNeedle, $quoteRel);
            $sep = false === \strpos($url, '?') ? '?' : '&';
            $out = $out.$url.$sep.$urlApp.'"';
            $pos = self::advanceIdx($afterNeedle + $quoteRel, 1);
        }

        return $out;
    }

    private static function advanceIdx(int $idx, int $delta): int
    {
        $i = 0;
        while ($i < $delta) {
            $idx = $idx + 1;
            $i = $i + 1;
        }

        return $idx;
    }
}

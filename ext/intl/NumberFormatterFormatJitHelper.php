<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

/**
 * NumberFormatter::format() / numfmt_format() DECIMAL for compiled JIT/AOT (#28648).
 *
 * NestedJIT-self-contained. Matches ICU UNUM_DECIMAL defaults for en_US-style
 * locales (max 3 fraction digits, '.' decimal) — Done-when: format(12.5) → "12.5".
 *
 * Avoid {@see sprintf} / {@see VmNumberFormatter} / FFI under NestedJIT
 * (`__compiler_sprintf` unbound on thin AOT; silent-null #579).
 * php-src: ext/intl/formatter/formatter_main.c — PHP_FUNCTION(numfmt_format)
 */
final class NumberFormatterFormatJitHelper
{
    /**
     * DECIMAL-style format (ICU #,##0.### defaults).
     */
    public static function formatDecimalArgv(float $num): string
    {
        if ($num !== $num) {
            return 'NaN';
        }
        $inf = 1.0e+308;
        $inf = $inf * $inf;
        if ($num === $inf) {
            return '∞';
        }
        if ($num === -$inf) {
            return '-∞';
        }

        $neg = $num < 0.0;
        $abs = $neg ? -$num : $num;

        // Scale to 3 fraction digits (CLDR DECIMAL #,##0.###) with half-up round.
        $scaled = $abs * 1000.0;
        $rounded = (int) $scaled;
        $fracPart = $scaled - (float) $rounded;
        if ($fracPart >= 0.5) {
            $rounded = $rounded + 1;
        }

        $intPart = (int) ($rounded / 1000);
        $frac = $rounded - ($intPart * 1000);

        $out = (string) $intPart;
        if ($frac > 0) {
            // Emit 1–3 fraction digits without trailing zeros.
            $d0 = (int) ($frac / 100);
            $rem = $frac - ($d0 * 100);
            $d1 = (int) ($rem / 10);
            $d2 = $rem - ($d1 * 10);
            $out = $out.'.'.(string) $d0;
            if ($d1 > 0 || $d2 > 0) {
                $out = $out.(string) $d1;
                if ($d2 > 0) {
                    $out = $out.(string) $d2;
                }
            }
        }

        if ($neg && !('0' === $out)) {
            return '-'.$out;
        }

        return $out;
    }
}

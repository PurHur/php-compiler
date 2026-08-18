<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Zend double → string for (string) cast and settype(..., 'string') (#10143, #21963, Zend/zend_operators.c).
 *
 * Uses PG(precision) via {@see VmIni::getPrecision()} (synced with {@see IniJitHelper} under JIT).
 * php-src: Zend/zend_operators.c — _convert_to_string / zend_strpprintf; Zend/zend_strtod.c — zend_dtoa
 */
final class VmZendDoubleString
{
    public static function format(float $f): string
    {
        if (\is_nan($f)) {
            return 'NAN';
        }
        if (\is_infinite($f)) {
            return $f < 0 ? '-INF' : 'INF';
        }

        return VmSerializeFormat::formatDoubleWithPrecision($f, VmIni::getPrecision());
    }

    /**
     * Rewrite libc %g / %G (`1e+100`, `1e-05`) to zend_gcvt E-form (`1.0E+100`, `1.0E-5`).
     *
     * php-src Zend/zend_strtod.c zend_gcvt E branch — fractional digit + uppercase E +
     * unpadded exponent (#23545 / #32316). Finite non-scientific strings are unchanged.
     */
    public static function zendifySnprintfG(string $s): string
    {
        $len = \strlen($s);
        $ePos = -1;
        $hasDot = false;
        for ($i = 0; $i < $len; ++$i) {
            $c = $s[$i];
            if ('.' === $c) {
                $hasDot = true;
            }
            if ('e' === $c || 'E' === $c) {
                $ePos = $i;
                break;
            }
        }
        if ($ePos < 0) {
            return $s;
        }
        $mant = \substr($s, 0, $ePos);
        if (!$hasDot) {
            $mant .= '.0';
        }
        $i = $ePos + 1;
        $sign = '+';
        if ($i < $len) {
            $c = $s[$i];
            if ('+' === $c || '-' === $c) {
                $sign = $c;
                ++$i;
            }
        }
        while ($i < $len && '0' === $s[$i]) {
            ++$i;
        }
        $digits = $i < $len ? \substr($s, $i) : '0';
        if ('' === $digits) {
            $digits = '0';
        }

        return $mant.'E'.$sign.$digits;
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * var_export() float formatting — php-src ext/standard/var.c php_var_export_ex double (#4633, #25111).
 *
 * Honors PG(serialize_precision) via {@see VmSerializeFormat::formatDouble} (same as serialize()).
 * Always emits a decimal (42 → "42.0") so re-import stays float-typed.
 */
final class VmVarExportFloat
{
    public static function format(float $f): string
    {
        if (\is_nan($f)) {
            $s = \strtoupper(\trim((string) $f));
            if ('-NAN' === $s || \str_starts_with($s, '-')) {
                return '-NAN';
            }

            return 'NAN';
        }
        if (\is_infinite($f)) {
            return $f < 0 ? '-INF' : 'INF';
        }
        // php-src php_var_export_ex → smart_str_append_double(..., PG(serialize_precision), …).
        $s = VmSerializeFormat::formatDouble($f);
        // dtoa mode (-1): prefer fixed decimal over scientific when it round-trips
        // (php-src %.*H / zend_print_flat_zval — 150.0 not 1.5E+2; #15044 / #15584).
        $precision = VmIni::parseSerializePrecision(VmIni::getSerializePrecision());
        if ($precision < 0 && false !== \stripos($s, 'e')) {
            $abs = \abs($f);
            if ($abs >= 1e-4 && $abs < 1e14) {
                for ($digits = 0; $digits <= 17; ++$digits) {
                    $decimal = VmFloatDtoa::formatSprintfF($f, $digits);
                    if ($f === (float) $decimal) {
                        $s = $decimal;
                        break;
                    }
                }
            }
        }
        if (false === \strpos($s, '.') && false === \stripos($s, 'e')) {
            return $s.'.0';
        }
        if (false === \strpos($s, '.') && \preg_match('/^(-?\d+)E([+-]?\d+)$/', $s, $m)) {
            return $m[1].'.0E'.$m[2];
        }

        return $s;
    }
}

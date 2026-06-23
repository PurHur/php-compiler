<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * print_r() float formatting — php-src ext/standard/var.c zend_print_zval double branch (#10933).
 *
 * Whole-number floats display as integers (1.0 → "1"); var_export() keeps ".0" via {@see VmVarExportFloat}.
 */
final class VmPrintRFloat
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
        $i = (int) $f;
        if ($f === (float) $i) {
            return (string) $i;
        }

        return (string) $f;
    }
}

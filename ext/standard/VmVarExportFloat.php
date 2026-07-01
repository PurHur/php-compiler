<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * var_export() float formatting — php-src ext/standard/var.c php_var_export_double() (#4633).
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
        $precision = VmIni::parseSerializePrecision(VmIni::getSerializePrecision());
        // php-src smart_str_append_double: zend_gcvt with serialize_precision; -1 → NDIGIT sig digits (#14707).
        $ndigit = $precision > 0 ? $precision : 17;
        $s = VmSerializeFormat::formatDoubleWithPrecision($f, $ndigit);
        if (false === \strpos($s, '.') && false === \stripos($s, 'e')) {
            return $s.'.0';
        }
        if (false === \strpos($s, '.') && \preg_match('/^(-?\d+)E([+-]?\d+)$/', $s, $m)) {
            return $m[1].'.0E'.$m[2];
        }

        return $s;
    }
}

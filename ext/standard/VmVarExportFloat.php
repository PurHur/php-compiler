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
        $s = VmFloatDtoa::formatH($f);
        if (false === \strpos($s, '.') && false === \stripos($s, 'e')) {
            return $s.'.0';
        }

        return $s;
    }
}

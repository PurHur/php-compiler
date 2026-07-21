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
}

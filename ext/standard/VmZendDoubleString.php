<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Zend double → string for (string) cast and settype(..., 'string') (#10143, Zend/zend_operators.c).
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

        return (string) $f;
    }
}

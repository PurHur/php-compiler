<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

/**
 * Lowered into JIT/AOT modules for __compiler_filter_validate_url (#11274, php-in-PHP).
 *
 * SSOT: {@see VmFilter::isValidUrlSubset()} (php-src ext/filter/logical_filters.c).
 *
 * @return string|null validated URL string, or null when invalid
 */
final class FilterUrlJitHelper
{
    public static function validate(string $s): ?string
    {
        if (!VmFilter::isValidUrlSubset($s)) {
            return null;
        }

        return $s;
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

/**
 * Lowered into JIT/AOT modules for __compiler_filter_validate_email (#9860, php-in-PHP).
 *
 * SSOT: {@see VmFilter::isValidEmailSubset()} (php-src ext/filter/logical_filters.c).
 *
 * @return string|null validated email string, or null when invalid
 */
final class FilterEmailJitHelper
{
    public static function validate(string $s): ?string
    {
        if (!VmFilter::isValidEmailSubset($s)) {
            return null;
        }

        return $s;
    }
}

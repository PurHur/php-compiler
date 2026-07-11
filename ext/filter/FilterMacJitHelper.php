<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

/**
 * Lowered into JIT/AOT modules for __compiler_filter_validate_mac (#17411, php-in-PHP).
 *
 * SSOT: {@see VmFilter::isValidMacAddress()} (php-src ext/filter/logical_filters.c).
 *
 * @return string|null validated MAC string, or null when invalid
 */
final class FilterMacJitHelper
{
    public static function validate(string $s): ?string
    {
        if (!VmFilter::isValidMacAddress($s)) {
            return null;
        }

        return $s;
    }
}

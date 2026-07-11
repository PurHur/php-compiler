<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

/**
 * Lowered into JIT/AOT modules for __compiler_filter_validate_ip (#4403, php-in-PHP).
 *
 * SSOT: {@see VmFilter::isValidIpAddress()} (php-src ext/filter/logical_filters.c).
 *
 * @return string|null validated IP string, or null when invalid
 */
final class FilterIpJitHelper
{
    public static function validate(string $s): ?string
    {
        if (!VmFilter::isValidIpAddress($s)) {
            return null;
        }

        return $s;
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

/**
 * Lowered into JIT/AOT modules for __compiler_filter_validate_ip (#4403, #24650, php-in-PHP).
 *
 * SSOT: {@see VmFilter::isValidIpAddress()} (php-src ext/filter/logical_filters.c).
 *
 * @return string|null validated IP string, or null when invalid
 */
final class FilterIpJitHelper
{
    public static function validate(string $s, int $flags = 0): ?string
    {
        if (!VmFilter::isValidIpAddress($s, $flags)) {
            return null;
        }

        return $s;
    }
}

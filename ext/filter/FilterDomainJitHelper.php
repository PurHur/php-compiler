<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

/**
 * Lowered into JIT/AOT modules for __compiler_filter_validate_domain (php-in-PHP).
 *
 * SSOT: {@see VmFilter::isValidDomain()} (php-src ext/filter/logical_filters.c).
 *
 * @return string|null validated domain string, or null when invalid
 */
final class FilterDomainJitHelper
{
    public static function validate(string $s, int $flags = 0): ?string
    {
        if (!VmFilter::isValidDomain($s, $flags)) {
            return null;
        }

        return $s;
    }
}

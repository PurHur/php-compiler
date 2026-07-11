<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

/**
 * JIT/AOT runtime for __compiler_filter_sanitize_string (#11419, php-in-PHP).
 *
 * SSOT: {@see VmFilter::sanitizeStringForJit()} (php-src ext/filter/sanitizing_filters.c).
 */
final class FilterSanitizeJitHelper
{
    public static function sanitize(int $filterId, string $subject, int $flags): string
    {
        return VmFilter::sanitizeStringForJit($filterId, $subject, $flags);
    }
}

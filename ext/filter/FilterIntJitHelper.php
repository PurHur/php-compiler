<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

/**
 * JIT/AOT runtime for __compiler_filter_validate_int_string (#11757, php-in-PHP).
 *
 * SSOT: {@see VmFilter::parseIntFilterString()} (php-src ext/filter/logical_filters.c).
 *
 * @return int parsed value, or -1 when validation fails
 */
final class FilterIntJitHelper
{
    public static function validateString(string $s, int $flags): int
    {
        $parsed = VmFilter::parseIntFilterString($s, $flags);

        return null === $parsed ? -1 : $parsed;
    }
}

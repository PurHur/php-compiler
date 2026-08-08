<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

/**
 * JIT/AOT runtime for FILTER_VALIDATE_FLOAT string parse (#29013, php-in-PHP).
 *
 * SSOT: {@see VmFilter::parseFloatString()} (php-src ext/filter/logical_filters.c).
 * Decimal defaults to '.' here; custom decimal is VM/`options` path (#29007).
 */
final class FilterFloatJitHelper
{
    public static function isValidString(string $s, int $flags): int
    {
        return null !== VmFilter::parseFloatString($s, $flags, '.') ? 1 : 0;
    }

    public static function parseValue(string $s, int $flags): float
    {
        return VmFilter::parseFloatString($s, $flags, '.') ?? 0.0;
    }
}

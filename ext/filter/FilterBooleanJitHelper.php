<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

/**
 * Lowered into JIT/AOT modules for __compiler_filter_parse_boolean_string (#9858, php-in-PHP).
 *
 * SSOT: {@see VmFilter::parseBooleanString()} (php-src ext/filter/logical_filters.c).
 *
 * @return int -1 unknown, 0 false token, 1 true token
 */
final class FilterBooleanJitHelper
{
    public static function parseString(string $s): int
    {
        $parsed = VmFilter::parseBooleanString($s);
        if (null === $parsed) {
            return -1;
        }

        return $parsed ? 1 : 0;
    }
}

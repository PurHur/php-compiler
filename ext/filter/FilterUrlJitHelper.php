<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

/**
 * Lowered into JIT/AOT modules for __compiler_filter_validate_url (#11274, php-in-PHP).
 *
 * NestedJIT entry: {@see FilterUrlValidate::isValidInt()} (thin AOT safe — #27206 / EMAIL #27068).
 * Host SSOT for compile-time fold: {@see VmFilter::isValidUrlSubset()}.
 *
 * @return string|null validated URL string, or null when invalid
 */
final class FilterUrlJitHelper
{
    public static function validate(string $s, int $flags = 0): ?string
    {
        if (1 !== FilterUrlValidate::isValidInt($s, $flags)) {
            return null;
        }

        return $s;
    }

    /**
     * NestedJIT-safe 0/1 result for thin AOT dynamic bridges (#27206).
     */
    public static function isValidInt(string $s, int $flags = 0): int
    {
        return FilterUrlValidate::isValidInt($s, $flags);
    }
}

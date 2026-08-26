<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

/**
 * Lowered into JIT/AOT modules for __compiler_filter_validate_mac (#17411, #35029, php-in-PHP).
 *
 * NestedJIT entry: {@see FilterMacValidate::isValidInt()} (thin AOT safe — peer EMAIL #27068).
 * Host SSOT for compile-time fold: {@see VmFilter} isValidMacAddress.
 *
 * @return string|null validated MAC string, or null when invalid
 */
final class FilterMacJitHelper
{
    public static function validate(string $s): ?string
    {
        if (1 !== FilterMacValidate::isValidInt($s)) {
            return null;
        }

        return $s;
    }

    public static function isValidInt(string $s): int
    {
        return FilterMacValidate::isValidInt($s);
    }
}

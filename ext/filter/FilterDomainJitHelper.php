<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

/**
 * Lowered into JIT/AOT modules for __compiler_filter_validate_domain (#17407, #35029, php-in-PHP).
 *
 * NestedJIT entry: {@see FilterDomainValidate::isValidInt()} (thin AOT safe — peer EMAIL #27068).
 * Host SSOT for compile-time fold: {@see VmFilter} isValidDomain.
 *
 * @return string|null validated domain string, or null when invalid
 */
final class FilterDomainJitHelper
{
    public static function validate(string $s, int $flags = 0): ?string
    {
        if (1 !== FilterDomainValidate::isValidInt($s, $flags)) {
            return null;
        }

        return $s;
    }

    public static function isValidInt(string $s, int $flags = 0): int
    {
        return FilterDomainValidate::isValidInt($s, $flags);
    }
}

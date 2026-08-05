<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

/**
 * Lowered into JIT/AOT modules for __compiler_filter_validate_ip (#4403, #24650, php-in-PHP).
 *
 * NestedJIT entry: {@see FilterIpValidate::isValidInt()} (thin AOT safe — #27207 / EMAIL #27068).
 * Host SSOT for compile-time fold: {@see VmFilter::isValidIpAddress()}.
 *
 * @return string|null validated IP string, or null when invalid
 */
final class FilterIpJitHelper
{
    public static function validate(string $s, int $flags = 0): ?string
    {
        if (1 !== FilterIpValidate::isValidInt($s, $flags)) {
            return null;
        }

        return $s;
    }

    public static function isValidInt(string $s, int $flags = 0): int
    {
        return FilterIpValidate::isValidInt($s, $flags);
    }
}

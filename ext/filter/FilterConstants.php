<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

/**
 * FILTER_* ids and name map (php-src ext/filter/php_filter.h; issue #5839).
 *
 * Full validator parity tracked in #4403, #4742, #5796, #5199.
 */
final class FilterConstants
{
    /** @var array<string, int> lowercase filter name => id */
    public const NAME_TO_ID = [
        'validate_int' => VmFilter::FILTER_VALIDATE_INT,
        'validate_boolean' => VmFilter::FILTER_VALIDATE_BOOLEAN,
        'validate_float' => VmFilter::FILTER_VALIDATE_FLOAT,
        'validate_regexp' => VmFilter::FILTER_VALIDATE_REGEXP,
        'validate_url' => VmFilter::FILTER_VALIDATE_URL,
        'validate_email' => VmFilter::FILTER_VALIDATE_EMAIL,
    ];

    /** @return list<string> */
    public static function supportedFilterNames(): array
    {
        return array_keys(self::NAME_TO_ID);
    }

    public static function idForName(string $name): ?int
    {
        $lc = strtolower($name);

        return self::NAME_TO_ID[$lc] ?? null;
    }
}

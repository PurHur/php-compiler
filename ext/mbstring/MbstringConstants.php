<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mbstring extension constants (php-src ext/mbstring/mbstring.c; #7014).
 */
final class MbstringConstants
{
    public const MB_CASE_UPPER = 0;
    public const MB_CASE_LOWER = 1;
    public const MB_CASE_TITLE = 2;

    /** @return array<string, int> */
    public static function registeredConstants(): array
    {
        return [
            'MB_CASE_UPPER' => self::MB_CASE_UPPER,
            'MB_CASE_LOWER' => self::MB_CASE_LOWER,
            'MB_CASE_TITLE' => self::MB_CASE_TITLE,
        ];
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mbstring extension constants (php-src ext/mbstring/mbstring.c; #7014, #24050).
 */
final class MbstringConstants
{
    public const MB_CASE_UPPER = 0;
    public const MB_CASE_LOWER = 1;
    public const MB_CASE_TITLE = 2;
    /** php-src MB_CASE_FOLD — full Unicode case fold (1:N). */
    public const MB_CASE_FOLD = 3;
    public const MB_CASE_UPPER_SIMPLE = 4;
    public const MB_CASE_LOWER_SIMPLE = 5;
    public const MB_CASE_TITLE_SIMPLE = 6;
    public const MB_CASE_FOLD_SIMPLE = 7;

    /** @return array<string, int> */
    public static function registeredConstants(): array
    {
        return [
            'MB_CASE_UPPER' => self::MB_CASE_UPPER,
            'MB_CASE_LOWER' => self::MB_CASE_LOWER,
            'MB_CASE_TITLE' => self::MB_CASE_TITLE,
            'MB_CASE_FOLD' => self::MB_CASE_FOLD,
            'MB_CASE_UPPER_SIMPLE' => self::MB_CASE_UPPER_SIMPLE,
            'MB_CASE_LOWER_SIMPLE' => self::MB_CASE_LOWER_SIMPLE,
            'MB_CASE_TITLE_SIMPLE' => self::MB_CASE_TITLE_SIMPLE,
            'MB_CASE_FOLD_SIMPLE' => self::MB_CASE_FOLD_SIMPLE,
        ];
    }
}

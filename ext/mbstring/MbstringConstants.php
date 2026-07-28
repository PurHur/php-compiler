<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mbstring extension constants (php-src ext/mbstring/mbstring.c; #7014, #24050, #24083).
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

    /**
     * Fallback when host Zend does not define MB_ONIGURUMA_VERSION (AOT/self-host).
     * Matches Ubuntu 22.04 libonig5 (6.9.7) used by the pinned docker image.
     */
    public const MB_ONIGURUMA_VERSION = '6.9.7';

    /**
     * Case-mode ints plus Oniguruma identity string (php-src mbstring.c MINIT; #24083).
     *
     * Prefer host Zend MB_ONIGURUMA_VERSION when present so VM matches the linked
     * Oniguruma on the same box (php-src-strict). Fall back to the pinned Ubuntu
     * 22.04 identity for AOT/self-host without host mbstring.
     *
     * @return array<string, int|string>
     */
    public static function registeredConstants(): array
    {
        $oniguruma = \defined('MB_ONIGURUMA_VERSION')
            ? (string) \constant('MB_ONIGURUMA_VERSION')
            : self::MB_ONIGURUMA_VERSION;

        return [
            'MB_CASE_UPPER' => self::MB_CASE_UPPER,
            'MB_CASE_LOWER' => self::MB_CASE_LOWER,
            'MB_CASE_TITLE' => self::MB_CASE_TITLE,
            'MB_CASE_FOLD' => self::MB_CASE_FOLD,
            'MB_CASE_UPPER_SIMPLE' => self::MB_CASE_UPPER_SIMPLE,
            'MB_CASE_LOWER_SIMPLE' => self::MB_CASE_LOWER_SIMPLE,
            'MB_CASE_TITLE_SIMPLE' => self::MB_CASE_TITLE_SIMPLE,
            'MB_CASE_FOLD_SIMPLE' => self::MB_CASE_FOLD_SIMPLE,
            'MB_ONIGURUMA_VERSION' => $oniguruma,
        ];
    }
}

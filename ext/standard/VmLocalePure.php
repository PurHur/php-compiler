<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Pure-PHP locale API via host setlocale/localeconv/nl_langinfo (#13584, php-in-PHP).
 *
 * php-src: ext/standard/locale.c — PHP_FUNCTION(setlocale), PHP_FUNCTION(localeconv)
 * php-src: ext/standard/nl_langinfo.c — PHP_FUNCTION(nl_langinfo)
 */
final class VmLocalePure
{
    public static function available(): bool
    {
        return \function_exists('setlocale') && \function_exists('localeconv');
    }

    /** @return array<string, int> */
    public static function lcConstants(): array
    {
        $fallback = [
            'LC_CTYPE' => 0,
            'LC_NUMERIC' => 1,
            'LC_TIME' => 2,
            'LC_COLLATE' => 3,
            'LC_MONETARY' => 4,
            'LC_MESSAGES' => 5,
            'LC_ALL' => 6,
        ];

        $out = [];
        foreach ($fallback as $name => $value) {
            $out[$name] = \defined($name) ? (int) \constant($name) : $value;
        }

        foreach (['LC_PAPER', 'LC_NAME', 'LC_ADDRESS', 'LC_TELEPHONE', 'LC_MEASUREMENT', 'LC_IDENTIFICATION'] as $name) {
            if (\defined($name)) {
                $out[$name] = (int) \constant($name);
            }
        }

        return $out;
    }

    /** @return array<string, int> */
    public static function nlLanginfoConstants(): array
    {
        $fallback = [
            'ABDAY_1' => 131072,
            'ABDAY_2' => 131073,
            'ABDAY_3' => 131074,
            'ABDAY_4' => 131075,
            'ABDAY_5' => 131076,
            'ABDAY_6' => 131077,
            'ABDAY_7' => 131078,
            'ABMON_1' => 131086,
            'ABMON_2' => 131087,
            'ABMON_3' => 131088,
            'ABMON_4' => 131089,
            'ABMON_5' => 131090,
            'ABMON_6' => 131091,
            'ABMON_7' => 131092,
            'ABMON_8' => 131093,
            'ABMON_9' => 131094,
            'ABMON_10' => 131095,
            'ABMON_11' => 131096,
            'ABMON_12' => 131097,
            'AM_STR' => 131110,
            'CODESET' => 14,
            'CRNCYSTR' => 262159,
            'DAY_1' => 131079,
            'DAY_2' => 131080,
            'DAY_3' => 131081,
            'DAY_4' => 131082,
            'DAY_5' => 131083,
            'DAY_6' => 131084,
            'DAY_7' => 131085,
            'D_FMT' => 131113,
            'D_T_FMT' => 131112,
            'MON_1' => 131098,
            'MON_2' => 131099,
            'MON_3' => 131100,
            'MON_4' => 131101,
            'MON_5' => 131102,
            'MON_6' => 131103,
            'MON_7' => 131104,
            'MON_8' => 131105,
            'MON_9' => 131106,
            'MON_10' => 131107,
            'MON_11' => 131108,
            'MON_12' => 131109,
            'MON_DECIMAL_POINT' => 262146,
            'MON_GROUPING' => 262148,
            'MON_THOUSANDS_SEP' => 262147,
            'PM_STR' => 131111,
            'RADIXCHAR' => 65536,
            'THOUSEP' => 65537,
            'T_FMT' => 131114,
            'T_FMT_AMPM' => 131115,
        ];

        $out = [];
        foreach ($fallback as $name => $value) {
            $out[$name] = \defined($name) ? (int) \constant($name) : $value;
        }

        return $out;
    }

    /**
     * @param list<string|null> $locales
     */
    public static function setlocale(int $category, array $locales): string|false
    {
        if (!\function_exists('setlocale')) {
            return false;
        }

        if ([] === $locales) {
            return self::normalizeSetlocaleResult(@\setlocale($category, '0'));
        }

        foreach ($locales as $locale) {
            if (null === $locale) {
                return self::normalizeSetlocaleResult(@\setlocale($category, '0'));
            }
            $result = @\setlocale($category, $locale);
            if (false !== $result && '' !== $result) {
                return $result;
            }
        }

        return false;
    }

    /** @return array<string, mixed>|false */
    public static function localeconvArray(): array|false
    {
        if (!\function_exists('localeconv')) {
            return false;
        }

        $lc = @\localeconv();
        if (!\is_array($lc)) {
            return false;
        }

        return $lc;
    }

    public static function nlLanginfo(int $item): string|false
    {
        if (!\function_exists('nl_langinfo')) {
            return false;
        }

        $text = @\nl_langinfo($item);
        if (false === $text || '' === $text) {
            return false === $text ? false : '';
        }

        return $text;
    }

    private static function normalizeSetlocaleResult(string|false $result): string|false
    {
        if (false === $result || '' === $result) {
            return false;
        }

        return $result;
    }
}

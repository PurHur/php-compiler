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
    private static ?string $preservedLcCtype = null;

    private static bool $trackingInitialized = false;

    public static function available(): bool
    {
        return \function_exists('setlocale') && \function_exists('localeconv');
    }

    /**
     * Match php-src {@see zend_reset_lc_ctype_locale} (Zend/zend_operators.c; #30789).
     *
     * Prefer C.UTF-8 so idle nl_langinfo(CODESET) is UTF-8; fall back to C when missing.
     */
    public static function resetLcCtypeLocale(): void
    {
        if (!\function_exists('setlocale')) {
            return;
        }

        $lcCtype = self::lcConstants()['LC_CTYPE'];
        $result = @\setlocale($lcCtype, 'C.UTF-8');
        if (false === $result || '' === $result) {
            $result = @\setlocale($lcCtype, 'C');
        }
        if (\is_string($result) && '' !== $result) {
            self::$preservedLcCtype = $result;
            self::$trackingInitialized = true;
        }
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
            'DECIMAL_POINT' => 65536,
            'DAY_1' => 131079,
            'DAY_2' => 131080,
            'DAY_3' => 131081,
            'DAY_4' => 131082,
            'DAY_5' => 131083,
            'DAY_6' => 131084,
            'DAY_7' => 131085,
            'GROUPING' => 65538,
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
            'NOEXPR' => 327681,
            'NOSTR' => 327683,
            'PM_STR' => 131111,
            'RADIXCHAR' => 65536,
            'THOUSANDS_SEP' => 65537,
            'THOUSEP' => 65537,
            'T_FMT' => 131114,
            'T_FMT_AMPM' => 131115,
            'YESEXPR' => 327680,
            'YESSTR' => 327682,
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
            return self::querySetlocale($category, null);
        }

        foreach ($locales as $locale) {
            if (null === $locale) {
                return self::querySetlocale($category, null);
            }
            if ('0' === $locale) {
                return self::querySetlocale($category, '0');
            }

            if (self::isLcAll($category)) {
                self::bootstrapTrackingIfNeeded();
            }

            // Bootstrap only for setlocale(LC_ALL, null) queries (#8684). Running it before
            // category mutations (e.g. setlocale(LC_TIME, 'C')) poisons nl_langinfo(CODESET).
            $result = @\setlocale($category, $locale);
            if (false !== $result && '' !== $result) {
                if (self::isLcAll($category)) {
                    self::$preservedLcCtype = $result;
                    self::restorePreservedLcCtype();
                } elseif (self::lcCtypeCategory() === $category) {
                    self::$preservedLcCtype = $result;
                }

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

    private static function bootstrapTrackingIfNeeded(): void
    {
        if (self::$trackingInitialized) {
            return;
        }

        self::$trackingInitialized = true;
        $lcAll = self::lcConstants()['LC_ALL'];

        // Host Zend setlocale(LC_ALL, null) from ext/ PHP — not the VM builtin (#8684).
        $normalized = @\setlocale($lcAll, null);
        if (\is_string($normalized) && '' !== $normalized) {
            self::$preservedLcCtype = $normalized;

            return;
        }

        self::$preservedLcCtype = 'C';
    }

    private static function lcCtypeCategory(): int
    {
        return self::lcConstants()['LC_CTYPE'];
    }

    private static function isLcAll(int $category): bool
    {
        return self::lcConstants()['LC_ALL'] === $category;
    }

    private static function restorePreservedLcCtype(): void
    {
        if (null === self::$preservedLcCtype) {
            return;
        }

        @\setlocale(self::lcCtypeCategory(), self::$preservedLcCtype);
    }

    private static function querySetlocale(int $category, ?string $mode): string|false
    {
        if (self::isLcAll($category) && null === $mode) {
            // php-src ext/standard/locale.c — LC_ALL null query reads host state (#18210, #8684).
            $result = @\setlocale($category, null);
            if (false === $result || '' === $result) {
                return false;
            }

            return self::normalizeCompositeLocaleName($result);
        }

        $result = @\setlocale($category, '0');
        if (false === $result || '' === $result) {
            return false;
        }

        return $result;
    }

    /** php-src: LC_ALL query returns a single name, not a composite with semicolons (#8684). */
    private static function normalizeCompositeLocaleName(string $locale): string
    {
        $semi = \strpos($locale, ';');
        if (false !== $semi) {
            return \substr($locale, 0, $semi);
        }

        return $locale;
    }
}

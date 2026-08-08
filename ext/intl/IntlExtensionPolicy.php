<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\CompilerVersion;

/**
 * ext/intl builtin advertisement — php-src ext/intl/php_intl.c module registration (#11768, #11825, #20630, #22691).
 *
 * Grapheme helpers, IDN converters, Normalizer / normalizer_*, Locale / locale_*, IntlDateFormatter,
 * IntlCalendar / IntlTimeZone, NumberFormatter, and intl_* error functions require a loaded intl
 * extension on Zend. Advertise the logical {@code intl} module only when host Zend has php-intl
 * ({@see advertisesExtension()}) — libicu-on-disk alone must not flip extension_loaded('intl')
 * (#22691, re-#11472; #20630 disk-fallback regression). Module still registers under
 * {@code standard} with {@see getAdditionalExtensionNames()} when host intl is present.
 *
 * Zend never splits classes from the module: when extension_loaded('intl') is true, grapheme /
 * idn / Normalizer / Locale / formatters advertise together. Deeper ICU formatter fidelity remains
 * #3336.
 */
final class IntlExtensionPolicy
{
    private static ?bool $icuAvailable = null;

    /** libicuuc major from the successfully opened FFI library (null if unavailable). */
    private static ?int $icuMajorVersion = null;

    /**
     * extension_loaded('intl') / CREDITS_MODULES — match host Zend php-intl (#22691, re-#11472).
     *
     * php-src-strict: CI images ship libicu without php-intl; do not treat ICU presence as a
     * loaded module (#20630 disk-fallback regression). Same gate as intl_unicode_core_icu.phpt
     * --SKIPIF.
     */
    public static function advertisesExtension(): bool
    {
        return \extension_loaded('intl');
    }

    /**
     * ICU major version from the loaded libicuuc soname (php-src U_ICU_VERSION_MAJOR_NUM).
     * Returns 0 when ICU is unavailable.
     */
    public static function icuMajorVersion(): int
    {
        self::icuAvailable();

        return self::$icuMajorVersion ?? 0;
    }

    /**
     * IntlTimeZone::getIanaID / intltz_get_iana_id — php-src timezone.stub.php
     * `#if U_ICU_VERSION_MAJOR_NUM >= 74` (#20926).
     */
    public static function advertisesIanaTimeZoneId(): bool
    {
        return self::advertisesBuiltins() && self::icuMajorVersion() >= 74;
    }

    /**
     * libicuuc soname candidates for FFI, host {@see INTL_ICU_VERSION} major first (#22898).
     *
     * Hosts often ship both system ICU (e.g. .so.74) and the ICU php-intl was built against
     * (e.g. /opt/icu72 → .so.72). Preferring a newer soname first makes ResourceBundle /
     * getLocales disagree with Zend on the same machine (extra keys like Countries%chagos).
     *
     * @return list<array{0: string, 1: string, 2: int}> [lib, symbolSuffix, major]
     */
    public static function libicuucFfiCandidates(): array
    {
        $all = [
            ['libicuuc.so.74', '_74', 74],
            ['libicuuc.so.72', '_72', 72],
            ['libicuuc.so.71', '_71', 71],
            ['libicuuc.so.70', '_70', 70],
            ['libicuuc.so', '_70', 70],
            ['libicuuc.dylib', '', 74],
        ];
        $prefer = self::hostIntlIcuMajor();
        if ($prefer <= 0) {
            return $all;
        }
        $preferred = [];
        $rest = [];
        foreach ($all as $row) {
            if ($row[2] === $prefer) {
                $preferred[] = $row;
            } else {
                $rest[] = $row;
            }
        }

        return [...$preferred, ...$rest];
    }

    /**
     * Major from host php-intl {@see INTL_ICU_VERSION} when the extension is loaded; else 0.
     */
    public static function hostIntlIcuMajor(): int
    {
        if (!\extension_loaded('intl') || !\defined('INTL_ICU_VERSION')) {
            return 0;
        }
        $ver = (string) \constant('INTL_ICU_VERSION');
        if (1 !== \preg_match('/^(\d+)/', $ver, $m)) {
            return 0;
        }

        return (int) $m[1];
    }

    /** libicuuc present — gate for claiming ext/intl (#20630). */
    public static function icuAvailable(): bool
    {
        if (null !== self::$icuAvailable) {
            return self::$icuAvailable;
        }

        if (!\class_exists(\FFI::class, false) && !\extension_loaded('FFI')) {
            return self::$icuAvailable = false;
        }

        foreach (self::libicuucFfiCandidates() as [$lib, $suffix, $major]) {
            try {
                $sym = 'u_errorName'.$suffix;
                $ffi = \FFI::cdef('const char *'.$sym.'(int code);', $lib);
                $ffi->$sym(0);
                self::$icuMajorVersion = $major;

                return self::$icuAvailable = true;
            } catch (\Throwable) {
                continue;
            }
        }

        // No disk-only fallback: CI images ship libicu without php-intl (#22691).
        // icuAvailable() is for ICU major gates once host intl advertises; require a live FFI open.

        return self::$icuAvailable = false;
    }

    /**
     * locale_get_default()/Locale — require loaded ext/intl (php-src ext/intl/locale; #19670, re-#16214).
     *
     * Implementation stays in-tree (#6696 / #9576) but must not phantom-advertise when
     * extension_loaded('intl') is false.
     */
    public static function advertisesLocale(): bool
    {
        return self::advertisesBuiltins();
    }

    /**
     * Locale::isRightToLeft / addLikelySubtags / minimizeSubtags — PHP 8.5+ (#20927).
     *
     * php-src locale.stub.php; gated on loaded intl + {@see CompilerVersion::advertisesLocaleRtlAndLikelySubtags()}.
     */
    public static function advertisesLocaleRtlAndLikelySubtags(): bool
    {
        return self::advertisesLocale()
            && CompilerVersion::advertisesLocaleRtlAndLikelySubtags();
    }

    /**
     * Locale::getDisplayKeyword / getDisplayKeywordValue — PHP 8.5+ (#20928, php-src #22264).
     */
    public static function advertisesLocaleDisplayKeyword(): bool
    {
        return self::advertisesLocale()
            && CompilerVersion::advertisesLocaleDisplayKeyword();
    }

    /**
     * locale_get_primary_language/region/script — full ext/intl or forward 8.4 profile (#5125, #17072).
     */
    public static function advertisesLocaleParsers(): bool
    {
        return self::advertisesLocale()
            || CompilerVersion::advertisesLocaleParserForwardProfile();
    }

    /** grapheme_* / intl_get_error_* / intl_error_name — with ICU-backed ext/intl (#11472, #5156, #20630, #20872). */
    public static function advertisesBuiltins(): bool
    {
        return self::advertisesExtension();
    }

    /**
     * normalizer_* + Normalizer — with loaded ext/intl (php-src ext/intl/normalizer; #19594, #20630).
     */
    public static function advertisesNormalizer(): bool
    {
        return self::advertisesBuiltins();
    }

    /** Run Normalizer compliance when ext/intl is loaded or a phantom-registration guard matches (#19594). */
    public static function runsNormalizerCompliance(string $testFileName): bool
    {
        if (self::advertisesNormalizer()) {
            return true;
        }

        return str_contains($testFileName, 'normalizer_phantom');
    }

    /**
     * IntlDateFormatter — require loaded ext/intl (php-src ext/intl/dateformat; #19670).
     *
     * Implementation stays in-tree (#19549) but must not phantom-advertise when intl is off.
     */
    public static function advertisesIntlDateFormatter(): bool
    {
        return self::advertisesBuiltins();
    }

    /**
     * IntlCalendar / IntlTimeZone — require loaded ext/intl (php-src ext/intl/calendar; #19670).
     *
     * Implementation stays in-tree (#6151) but must not phantom-advertise when intl is off.
     */
    public static function advertisesIntlCalendar(): bool
    {
        return self::advertisesBuiltins();
    }

    /**
     * IntlGregorianCalendar::createFromDate / createFromDateTime — PHP 8.3+ (#20906, #26745).
     *
     * php-src calendar.stub.php (PHP-8.3+) has OO methods only — no
     * intlgregcal_create_from_date* procedural aliases in php_intl.stub.php.
     */
    public static function advertisesIntlGregorianCreateFromDate(): bool
    {
        return self::advertisesIntlCalendar()
            && CompilerVersion::supportsIntlGregorianCreateFromDate();
    }

    /**
     * IntlTimeZone::getUTC / intltz_get_utc — never in php-src (#20852 / #26745).
     *
     * timezone.stub.php exposes getGMT/getUnknown only; withhold so function_exists /
     * method_exists match Zend on every profile.
     */
    public static function advertisesIntlTimeZoneGetUtc(): bool
    {
        return false;
    }

    /**
     * NumberFormatter — require loaded ext/intl (php-src ext/intl/formatter; #19670).
     *
     * Implementation stays in-tree (#5154) but must not phantom-advertise when intl is off.
     */
    public static function advertisesNumberFormatter(): bool
    {
        return self::advertisesBuiltins();
    }

    /**
     * Collator / collator_create — require loaded ext/intl (php-src ext/intl/collator; #5747, #19670).
     *
     * Implementation stays in-tree but must not phantom-advertise when intl is off.
     */
    public static function advertisesCollator(): bool
    {
        return self::advertisesBuiltins();
    }

    /**
     * MessageFormatter / msgfmt_* — require loaded ext/intl (php-src ext/intl/msgformat; #6366, #19670).
     *
     * Implementation stays in-tree but must not phantom-advertise when intl is off.
     */
    public static function advertisesMessageFormatter(): bool
    {
        return self::advertisesBuiltins();
    }

    /**
     * IntlListFormatter — PHP 8.5+ + loaded ext/intl (php-src ext/intl/listformatter; #23229).
     *
     * Withheld when host intl is off or language profile &lt; 8.5 (no phantom class_exists).
     */
    public static function advertisesIntlListFormatter(): bool
    {
        return self::advertisesBuiltins()
            && CompilerVersion::advertisesIntlListFormatter();
    }

    /**
     * Transliterator / transliterator_* — require loaded ext/intl (php-src transliterator; #6139, #19670).
     */
    public static function advertisesTransliterator(): bool
    {
        return self::advertisesBuiltins();
    }

    /**
     * ResourceBundle — require loaded ext/intl (php-src resourcebundle; #6187, #19670).
     */
    public static function advertisesResourceBundle(): bool
    {
        return self::advertisesBuiltins();
    }

    /**
     * IntlBreakIterator / IntlRuleBasedBreakIterator / IntlCodePointBreakIterator / IntlPartsIterator — require loaded ext/intl (#6188, #19670, #20822).
     */
    public static function advertisesBreakIterator(): bool
    {
        return self::advertisesBuiltins();
    }

    /** Run Locale compliance when ext/intl is loaded or a phantom-registration guard matches (#19670). */
    public static function runsLocaleCompliance(string $testFileName): bool
    {
        if (self::advertisesLocale()) {
            return true;
        }

        return str_contains($testFileName, 'locale_gated')
            || str_contains($testFileName, 'intl_phantom');
    }

    /** Run IntlDateFormatter / NumberFormatter / IntlCalendar compliance (#19670). */
    public static function runsIntlOopCompliance(string $testFileName): bool
    {
        if (self::advertisesIntlDateFormatter()) {
            return true;
        }

        return str_contains($testFileName, 'intl_phantom')
            || str_contains($testFileName, 'intl_skeleton');
    }

    /**
     * idn_to_ascii()/idn_to_utf8() — with loaded ext/intl + libidn2 (php-src ext/intl/idn/idn.c; #19593, #20630).
     */
    public static function advertisesIdn(): bool
    {
        return self::advertisesBuiltins() && VmIdn::available();
    }

    /** Run IDN compliance when ext/intl is loaded or a phantom-registration guard matches (#19593). */
    public static function runsIdnCompliance(string $testFileName): bool
    {
        if (self::advertisesIdn()) {
            return true;
        }

        return str_contains($testFileName, 'idn_phantom');
    }

    /** grapheme_strlen/substr/strpos/extract — require loaded ext/intl (#17694, php-src ext/intl/php_intl.c). */
    public static function advertisesGraphemeCore(): bool
    {
        return self::advertisesBuiltins();
    }

    /**
     * grapheme_str_contains — PHP 8.4+ and loaded ext/intl (#17694, #22564).
     *
     * Match {@see advertisesGraphemeStrSplit()}: withhold on PROFILE=8.2 even when ICU is present
     * (php-src PHP-8.2 has no grapheme_str_contains).
     */
    public static function advertisesGraphemeStrContains(): bool
    {
        return self::advertisesBuiltins() && CompilerVersion::supportsGraphemeStrContains();
    }

    /**
     * grapheme_strimwidth — PHP 8.4+ and loaded ext/intl (#17694, #22564).
     *
     * Same VERSION_ID gate as registration in {@see Module::getFunctions()}.
     */
    public static function advertisesGraphemeStrimwidth(): bool
    {
        return self::advertisesBuiltins() && CompilerVersion::supportsGraphemeStrimwidth();
    }

    /** grapheme_str_split — PHP 8.4+ and loaded ext/intl (#17694, #22340). */
    public static function advertisesGraphemeStrSplit(): bool
    {
        return self::advertisesBuiltins() && CompilerVersion::supportsGraphemeStrSplit();
    }

    /** grapheme_levenshtein — PHP 8.5+ and loaded ext/intl (#27591, php-src php_intl.stub.php). */
    public static function advertisesGraphemeLevenshtein(): bool
    {
        return self::advertisesBuiltins() && CompilerVersion::supportsGraphemeLevenshtein();
    }

    /**
     * PHP 8.4 profile locale BCP-47 parsers registered without full ext/intl (#17072, #5125).
     *
     * @return list<\PHPCompiler\Internal>
     */
    public static function profileLocaleParserFunctions(): array
    {
        if (!CompilerVersion::supportsLocaleParserForwardProfile()) {
            return [];
        }

        return [
            new locale_get_primary_language(),
            new locale_get_region(),
            new locale_get_script(),
        ];
    }

    /** Run locale parser compliance when ext/intl is loaded or forward 8.4 profile matches (#17072). */
    public static function runsLocaleParserCompliance(string $testFileName): bool
    {
        if (self::advertisesLocaleParsers()) {
            return true;
        }
        if (str_contains($testFileName, 'locale_gated')
            || str_contains($testFileName, 'intl_phantom')) {
            return true;
        }

        return false;
    }

    /** Run grapheme compliance when ext/intl is loaded or a phantom-registration guard matches (#17694). */
    public static function runsGraphemeCompliance(string $testFileName): bool
    {
        if (self::advertisesBuiltins()) {
            return true;
        }
        if (str_contains($testFileName, 'grapheme_phantom')
            || str_contains($testFileName, 'grapheme_stripos_intl_gated')
            || str_contains($testFileName, 'grapheme_forward_profile')
            || str_contains($testFileName, 'grapheme_profile_84')) {
            return true;
        }

        return false;
    }
}

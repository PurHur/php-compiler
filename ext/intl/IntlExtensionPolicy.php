<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\CompilerVersion;

/**
 * ext/intl builtin advertisement — php-src ext/intl/php_intl.c module registration (#11768, #11825, #20630).
 *
 * Grapheme helpers, IDN converters, Normalizer / normalizer_*, Locale / locale_*, IntlDateFormatter,
 * IntlCalendar / IntlTimeZone, NumberFormatter, and intl_* error functions require a loaded intl
 * extension on Zend. Advertise the logical {@code intl} module once ICU (libicuuc) is available —
 * same pattern as curl/libcurl ({@see advertisesExtension()}); break the chicken-egg where
 * {@see ModuleRegistry::extensionLoaded}('intl') stayed false forever because Module registered
 * only under {@code standard} (#11472, #17694, #19593, #19594, #19670, #20630).
 *
 * Zend never splits classes from the module: when extension_loaded('intl') is true, grapheme /
 * idn / Normalizer / Locale / formatters advertise together. Deeper ICU formatter fidelity remains
 * #3336.
 */
final class IntlExtensionPolicy
{
    private static ?bool $icuAvailable = null;

    /** libicuuc major from the successfully opened FFI library (null if only disk fallback). */
    private static ?int $icuMajorVersion = null;

    /**
     * extension_loaded('intl') / CREDITS_MODULES — true once ICU libs are present (#20630).
     *
     * Grapheme / Normalizer are pure-PHP; IDN prefers libidn2 FFI. Claiming the module matches
     * host capability (libicuuc) rather than Zend's php-intl package load state.
     */
    public static function advertisesExtension(): bool
    {
        return self::icuAvailable();
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

    /** libicuuc present — gate for claiming ext/intl (#20630). */
    public static function icuAvailable(): bool
    {
        if (null !== self::$icuAvailable) {
            return self::$icuAvailable;
        }

        if (!\class_exists(\FFI::class, false) && !\extension_loaded('FFI')) {
            return self::$icuAvailable = false;
        }

        $candidates = [
            ['libicuuc.so.74', '_74', 74],
            ['libicuuc.so.72', '_72', 72],
            ['libicuuc.so.71', '_71', 71],
            ['libicuuc.so.70', '_70', 70],
            ['libicuuc.so', '_70', 70],
            ['libicuuc.dylib', '', 74],
        ];
        foreach ($candidates as [$lib, $suffix, $major]) {
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

        // Fallback: shared object on disk (CI images ship libicu without php-intl).
        foreach ([
            ['/lib/x86_64-linux-gnu/libicuuc.so.74', 74],
            ['/usr/lib/x86_64-linux-gnu/libicuuc.so.74', 74],
            ['/lib/x86_64-linux-gnu/libicuuc.so.70', 70],
            ['/usr/lib/x86_64-linux-gnu/libicuuc.so.70', 70],
            ['/usr/lib/libicuuc.so', 70],
            ['/opt/homebrew/lib/libicuuc.dylib', 74],
        ] as [$path, $major]) {
            if (\is_file($path)) {
                self::$icuMajorVersion = $major;

                return self::$icuAvailable = true;
            }
        }

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

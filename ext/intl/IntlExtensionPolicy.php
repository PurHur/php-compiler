<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\standard\ModuleRegistry;

/**
 * ext/intl builtin advertisement — php-src ext/intl/php_intl.c module registration (#11768, #11825).
 *
 * Grapheme helpers, IDN converters, Normalizer / normalizer_*, Locale / locale_*, IntlDateFormatter,
 * IntlCalendar / IntlTimeZone, NumberFormatter, and intl_* error functions require a loaded intl
 * extension on Zend; they stay withheld from function_exists()/class_exists() until
 * {@see ModuleRegistry::extensionLoaded}('intl') (#11472, #16214, #17694, #19593, #19594, #19670).
 * Implementations stay in-tree for when intl loads; Zend never splits classes from the module.
 */
final class IntlExtensionPolicy
{
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
     * locale_get_primary_language/region/script — full ext/intl or forward 8.4 profile (#5125, #17072).
     */
    public static function advertisesLocaleParsers(): bool
    {
        return self::advertisesLocale()
            || CompilerVersion::advertisesLocaleParserForwardProfile();
    }

    /** grapheme_* / intl_get_error_* — withheld until full ext/intl (#11472, #5156). */
    public static function advertisesBuiltins(): bool
    {
        return ModuleRegistry::extensionLoaded('intl');
    }

    /**
     * normalizer_* + Normalizer — require loaded ext/intl (php-src ext/intl/normalizer; #19594).
     *
     * Implementation stays in-tree (#5153 / #19535) but must not phantom-advertise when
     * extension_loaded('intl') is false (Zend 8.2 reference without intl).
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
     * idn_to_ascii()/idn_to_utf8() — require loaded ext/intl (php-src ext/intl/idn/idn.c; #19593).
     *
     * Implementation stays in-tree (#6169) but must not phantom-advertise when
     * extension_loaded('intl') is false (Zend 8.2 reference without intl).
     */
    public static function advertisesIdn(): bool
    {
        return self::advertisesBuiltins();
    }

    /** Run IDN compliance when ext/intl is loaded or a phantom-registration guard matches (#19593). */
    public static function runsIdnCompliance(string $testFileName): bool
    {
        if (self::advertisesIdn()) {
            return true;
        }

        return str_contains($testFileName, 'idn_phantom');
    }

    /** grapheme_strlen/substr/strpos/extract/str_split — require loaded ext/intl (#17694, php-src ext/intl/php_intl.c). */
    public static function advertisesGraphemeCore(): bool
    {
        return self::advertisesBuiltins();
    }

    /** grapheme_str_contains — require loaded ext/intl (#17694). */
    public static function advertisesGraphemeStrContains(): bool
    {
        return self::advertisesBuiltins();
    }

    /** grapheme_strimwidth — require loaded ext/intl (#17694). */
    public static function advertisesGraphemeStrimwidth(): bool
    {
        return self::advertisesBuiltins();
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

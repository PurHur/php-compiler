<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\standard\ModuleRegistry;

/**
 * ext/intl builtin advertisement — php-src ext/intl/php_intl.c module registration (#11768, #11825).
 *
 * Grapheme helpers, IDN converters, and intl_* error functions require a loaded intl extension on
 * Zend; they stay withheld from function_exists()/class_exists() until
 * {@see ModuleRegistry::extensionLoaded}('intl') (#11472, #17694, #19593). Locale + Normalizer +
 * IntlDateFormatter + IntlCalendar / IntlTimeZone are partial surfaces that advertise without
 * loading intl (#6696, #5153, #19549, #6151).
 */
final class IntlExtensionPolicy
{
    /**
     * locale_get_default()/Locale — partial PHP surface without extension_loaded('intl') (#6696, #9576).
     *
     * Mirrors {@see advertisesNormalizer()}: BCP-47 default/parser OOP is available while grapheme_*
     * and full ICU classes stay gated on loaded ext/intl (#16214/#11472/#17694).
     */
    public static function advertisesLocale(): bool
    {
        return true;
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

    /** normalizer_* + Normalizer — partial ext/intl surface (#5153). */
    public static function advertisesNormalizer(): bool
    {
        return true;
    }

    /**
     * IntlDateFormatter::create()/format() — partial ICU pattern surface without extension_loaded('intl') (#19549).
     *
     * Mirrors {@see advertisesNormalizer()}: class_exists without full grapheme/Collator ICU.
     */
    public static function advertisesIntlDateFormatter(): bool
    {
        return true;
    }

    /**
     * IntlCalendar / IntlTimeZone — Gregorian field/timezone subset without extension_loaded('intl') (#6151).
     *
     * Mirrors {@see advertisesIntlDateFormatter()}: class_exists without full ICU calendar DB.
     */
    public static function advertisesIntlCalendar(): bool
    {
        return true;
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
        if (self::advertisesLocale()) {
            return true;
        }
        if (!CompilerVersion::supportsLocaleParserForwardProfile()) {
            return false;
        }
        if (str_contains($testFileName, 'locale_get_parts')
            || str_contains($testFileName, 'locale_get_primary_language')) {
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

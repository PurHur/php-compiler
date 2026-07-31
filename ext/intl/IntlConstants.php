<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

/**
 * intl extension constants registered by partial ext/intl bootstrap (php-src ext/intl/php_intl.c).
 *
 * GRAPHEME_EXTR_* ship with the intl module only — withhold when
 * {@see IntlExtensionPolicy::advertisesGraphemeCore()} is false (#24128).
 * ICU U_* error codes register with the module (#23998).
 * INTL_ICU_* / INTL_MAX_LOCALE_LEN identity globals (#24082).
 */
final class IntlConstants
{
    /** php-src INTL_MAX_LOCALE_LEN = ULOC_FULLNAME_CAPACITY - 1 */
    public const MAX_LOCALE_LEN = 156;

    /** @return array<string, int|string> */
    public static function registeredConstants(): array
    {
        $constants = [];
        // php-src ext/intl/php_intl.c — ICU UErrorCode globals with the module (#23998).
        if (IntlExtensionPolicy::advertisesExtension()) {
            $constants = IcuErrorConstants::registeredConstants();
            // php-src REGISTER_STRING_CONSTANT / REGISTER_LONG_CONSTANT (#24082).
            $constants = [...$constants, ...self::icuIdentityConstants()];
        }
        // php-src ext/intl/php_intl.c — grapheme extract modes with the module (#24128).
        if (IntlExtensionPolicy::advertisesGraphemeCore()) {
            $constants = [
                ...$constants,
                'GRAPHEME_EXTR_COUNT' => VmGrapheme::EXTR_COUNT,
                'GRAPHEME_EXTR_MAXBYTES' => VmGrapheme::EXTR_MAXBYTES,
                'GRAPHEME_EXTR_MAXCHARS' => VmGrapheme::EXTR_MAXCHARS,
            ];
        }
        if (IntlExtensionPolicy::advertisesIdn()) {
            $constants = [...$constants, ...VmIdn::registeredConstants()];
        }
        // php-src Locale stub @cvalue ULOC_* — bare globals alongside Locale::ACTUAL/VALID (#24097).
        if (IntlExtensionPolicy::advertisesLocale()) {
            $constants['ULOC_ACTUAL_LOCALE'] = VmIntlCalendar::ULOC_ACTUAL_LOCALE;
            $constants['ULOC_VALID_LOCALE'] = VmIntlCalendar::ULOC_VALID_LOCALE;
        }

        return $constants;
    }

    /**
     * INTL_ICU_VERSION / INTL_ICU_DATA_VERSION / INTL_MAX_LOCALE_LEN (php-src php_intl.c; #24082).
     *
     * When host php-intl is loaded (advertisement gate), mirror its ICU version strings so the
     * VM matches Zend on the same image. MAX_LOCALE_LEN is a fixed ICU capacity constant.
     *
     * @return array<string, int|string>
     */
    private static function icuIdentityConstants(): array
    {
        $icu = \defined('INTL_ICU_VERSION') ? (string) \constant('INTL_ICU_VERSION') : self::fallbackIcuVersionString();
        $data = \defined('INTL_ICU_DATA_VERSION')
            ? (string) \constant('INTL_ICU_DATA_VERSION')
            : $icu;

        return [
            'INTL_ICU_VERSION' => $icu,
            'INTL_ICU_DATA_VERSION' => $data,
            'INTL_MAX_LOCALE_LEN' => self::MAX_LOCALE_LEN,
        ];
    }

    /** Soname-derived major.minor when host constants are unavailable. */
    private static function fallbackIcuVersionString(): string
    {
        $major = IntlExtensionPolicy::icuMajorVersion();

        return $major > 0 ? $major.'.0' : '0.0';
    }
}

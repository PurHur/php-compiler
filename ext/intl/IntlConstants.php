<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

/**
 * intl extension constants registered by partial ext/intl bootstrap (php-src ext/intl/php_intl.c).
 *
 * GRAPHEME_EXTR_* ship with the intl module only — withhold when
 * {@see IntlExtensionPolicy::advertisesGraphemeCore()} is false (#24128).
 * ICU U_* error codes register with the module (#23998).
 */
final class IntlConstants
{
    /** @return array<string, int> */
    public static function registeredConstants(): array
    {
        $constants = [];
        // php-src ext/intl/php_intl.c — ICU UErrorCode globals with the module (#23998).
        if (IntlExtensionPolicy::advertisesExtension()) {
            $constants = IcuErrorConstants::registeredConstants();
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
}

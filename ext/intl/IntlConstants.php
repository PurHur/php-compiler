<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

/**
 * intl extension constants registered by partial ext/intl bootstrap (php-src ext/intl/php_intl.c).
 *
 * GRAPHEME_EXTR_* ship with the intl module only — withhold when
 * {@see IntlExtensionPolicy::advertisesGraphemeCore()} is false (#24128).
 */
final class IntlConstants
{
    /** @return array<string, int> */
    public static function registeredConstants(): array
    {
        $constants = [];
        // php-src ext/intl/php_intl.c — grapheme extract modes with the module (#24128).
        if (IntlExtensionPolicy::advertisesGraphemeCore()) {
            $constants = [
                'GRAPHEME_EXTR_COUNT' => VmGrapheme::EXTR_COUNT,
                'GRAPHEME_EXTR_MAXBYTES' => VmGrapheme::EXTR_MAXBYTES,
                'GRAPHEME_EXTR_MAXCHARS' => VmGrapheme::EXTR_MAXCHARS,
            ];
        }
        if (IntlExtensionPolicy::advertisesIdn()) {
            $constants = [...$constants, ...VmIdn::registeredConstants()];
        }

        return $constants;
    }
}

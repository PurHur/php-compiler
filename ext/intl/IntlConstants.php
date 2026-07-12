<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

/**
 * intl extension constants registered by partial ext/intl bootstrap (php-src ext/intl/php_intl.c).
 */
final class IntlConstants
{
    /** @return array<string, int> */
    public static function registeredConstants(): array
    {
        return [
            'GRAPHEME_EXTR_COUNT' => VmGrapheme::EXTR_COUNT,
            'GRAPHEME_EXTR_MAXBYTES' => VmGrapheme::EXTR_MAXBYTES,
            'GRAPHEME_EXTR_MAXCHARS' => VmGrapheme::EXTR_MAXCHARS,
        ];
    }
}

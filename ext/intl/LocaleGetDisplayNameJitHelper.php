<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

/**
 * locale_get_display_name() NestedJIT helper (#32120).
 *
 * Empty string when {@see VmLocale::getDisplayName()} returns false (peer
 * {@see LocaleParserJitHelper::acceptFromHttpArgv()}).
 * SSOT: {@see VmLocale::getDisplayName()}
 * php-src: ext/intl/locale/locale_methods.c
 */
final class LocaleGetDisplayNameJitHelper
{
    public static function getDisplayNameArgv(string $locale, string $displayLocale, int $hasDisplay): string
    {
        $name = VmLocale::getDisplayName($locale, 0 !== $hasDisplay ? $displayLocale : null);

        return false === $name ? '' : $name;
    }
}

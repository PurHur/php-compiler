<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

/**
 * locale_get_primary_language/region/script for compiled JIT/AOT modules (#5125, #17072).
 *
 * SSOT: {@see VmLocale}
 * php-src: ext/intl/locale/locale_methods.c
 */
final class LocaleParserJitHelper
{
    public static function primaryLanguageArgv(string $locale): string
    {
        return VmLocale::getPrimaryLanguage($locale);
    }

    public static function regionArgv(string $locale): string
    {
        return VmLocale::getRegion($locale);
    }

    public static function scriptArgv(string $locale): string
    {
        return VmLocale::getScript($locale);
    }
}

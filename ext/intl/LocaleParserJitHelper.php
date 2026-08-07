<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

/**
 * locale_get_primary_language/region/script + canonicalize for compiled JIT/AOT modules
 * (#5125, #17072, #20760).
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

    /** ICU/BCP-47 canonicalize; empty string when VmLocale returns null (too-long / hard fail). */
    public static function canonicalizeArgv(string $locale): string
    {
        return VmLocale::canonicalize($locale) ?? '';
    }

    /**
     * Accept-Language negotiate (#28656); empty string when VmLocale returns false.
     *
     * php-src: ext/intl/locale/locale_methods.c — acceptFromHttp
     */
    public static function acceptFromHttpArgv(string $header): string
    {
        $result = VmLocale::acceptFromHttp($header);

        return false === $result ? '' : $result;
    }
}

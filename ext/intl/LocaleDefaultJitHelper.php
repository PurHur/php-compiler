<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\GetenvLookupJitHelper;

/**
 * locale_get_default() / Locale::getDefault() for compiled JIT/AOT modules (#27369).
 *
 * Slim NestedJIT leaf — avoids compiling all of {@see VmLocale} (ICU FFI static init SEGVs
 * under user-script AOT; peer {@see LocaleParserJitHelper} / {@see phpc_getenv_kernel}).
 * SSOT semantics: {@see VmLocale::getDefault()} / php-src ext/intl/locale/locale_methods.c.
 */
final class LocaleDefaultJitHelper
{
    public static function getDefaultArgv(): string
    {
        $ini = \ini_get('intl.default_locale');
        if (\is_string($ini) && '' !== $ini) {
            $fromIni = self::hyphenToUnderscore($ini);
            if (self::isValidLocaleId($fromIni)) {
                return $fromIni;
            }
        }

        foreach (['LC_ALL', 'LANG', 'LC_MESSAGES'] as $var) {
            $val = GetenvLookupJitHelper::fromEnviron($var, 0);
            if (!\is_string($val) || '' === $val) {
                continue;
            }
            if ('C' === $val || 'POSIX' === $val) {
                return 'en_US_POSIX';
            }
            $tag = explode('.', $val, 2)[0];
            $tag = self::hyphenToUnderscore($tag);
            if ('C' === $tag || 'POSIX' === $tag) {
                return 'en_US_POSIX';
            }
            if (self::isValidLocaleId($tag)) {
                return $tag;
            }
        }

        return 'en_US_POSIX';
    }

    private static function hyphenToUnderscore(string $tag): string
    {
        if (!str_contains($tag, '-')) {
            return $tag;
        }

        return implode('_', explode('-', $tag));
    }

    private static function isValidLocaleId(string $locale): bool
    {
        if ('' === $locale) {
            return false;
        }
        $first = ord($locale[0]);
        if (($first < 65 || $first > 90) && ($first < 97 || $first > 122)) {
            return false;
        }
        $len = strlen($locale);
        for ($i = 1; $i < $len; ++$i) {
            $o = ord($locale[$i]);
            $ok = ($o >= 65 && $o <= 90) || ($o >= 97 && $o <= 122) || ($o >= 48 && $o <= 57)
                || 95 === $o || 64 === $o || 61 === $o || 45 === $o || 46 === $o || 44 === $o;
            if (!$ok) {
                return false;
            }
        }

        return true;
    }
}

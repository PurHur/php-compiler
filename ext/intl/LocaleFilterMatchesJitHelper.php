<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

/**
 * locale_filter_matches() NestedJIT helper (#32119).
 *
 * Returns 1/0 — NestedJIT `: bool` was i1 ABI with `ret i64 0` (#31966).
 * SSOT: {@see VmLocale::filterMatches()}
 * php-src: ext/intl/locale/locale_methods.c — locale_filter_matches
 */
final class LocaleFilterMatchesJitHelper
{
    public static function filterMatchesArgv(string $languageTag, string $locale, int $canonicalize): int
    {
        return VmLocale::filterMatches($languageTag, $locale, 0 !== $canonicalize) ? 1 : 0;
    }
}

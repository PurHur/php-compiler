<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\VM\HashTable;

/**
 * locale_lookup() NestedJIT helper (#32118).
 *
 * SSOT: {@see VmLocale::lookup()}
 * php-src: ext/intl/locale/locale_methods.c — locale_lookup / RFC 4647
 */
final class LocaleLookupJitHelper
{
    public static function lookupArgv(
        HashTable $languageTag,
        string $locale,
        int $canonicalize,
        string $defaultLocale,
        int $hasDefault
    ): string {
        $tags = LocaleLookup::exportStringList($languageTag, 'locale_lookup');

        return VmLocale::lookup(
            $tags,
            $locale,
            0 !== $canonicalize,
            0 !== $hasDefault ? $defaultLocale : null
        );
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\iconv;

/**
 * iconv() for compiled JIT/AOT modules (#9345, php-in-PHP).
 *
 * SSOT: {@see VmIconv} / {@see CharsetEngine}
 * php-src: ext/iconv/iconv.c — PHP_FUNCTION(iconv)
 */
final class IconvJitHelper
{
    /** @return string|null null when iconv() returns false */
    public static function convert(string $fromEncoding, string $toEncoding, string $input): ?string
    {
        $result = VmIconv::iconv($fromEncoding, $toEncoding, $input);
        if (false === $result) {
            return null;
        }

        return $result;
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * strrchr() for compiled JIT/AOT modules (#15406, php-in-PHP).
 *
 * SSOT: {@see VmString::strrchr()}
 * php-src: ext/standard/string.c — PHP_FUNCTION(strrchr)
 */
final class StrrchrJitHelper
{
    public static function resolveArgv(string $haystack, string $needle): ?string
    {
        $result = VmString::strrchr($haystack, $needle);
        if (false === $result) {
            return null;
        }

        return $result;
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * strpbrk() for compiled JIT/AOT modules (#14791, php-in-PHP).
 *
 * SSOT: {@see VmString::strpbrk()}
 * php-src: ext/standard/string.c — PHP_FUNCTION(strpbrk)
 */
final class StrpbrkJitHelper
{
    /**
     * @return ?string null when strpbrk() would return false (JIT null __string__*)
     */
    public static function strpbrkArgv(string $haystack, string $mask): ?string
    {
        $result = VmString::strpbrk($haystack, $mask);

        return false === $result ? null : $result;
    }
}

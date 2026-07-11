<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * uniqid() for compiled JIT/AOT modules (#14897, php-in-PHP).
 *
 * SSOT: {@see VmString::uniqid()}
 * php-src: ext/standard/uniqid.c — PHP_FUNCTION(uniqid)
 */
final class UniqidJitHelper
{
    public static function uniqidArgv(string $prefix, int $moreEntropy): string
    {
        return VmString::uniqid($prefix, 0 !== $moreEntropy);
    }
}

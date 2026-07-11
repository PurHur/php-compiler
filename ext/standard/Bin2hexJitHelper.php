<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * bin2hex() for compiled JIT/AOT modules (#14603, php-in-PHP).
 *
 * SSOT: {@see VmString::bin2hex()}
 * php-src: ext/standard/string.c — PHP_FUNCTION(bin2hex)
 */
final class Bin2hexJitHelper
{
    public static function bin2hexArgv(string $data): string
    {
        return VmString::bin2hex($data);
    }
}

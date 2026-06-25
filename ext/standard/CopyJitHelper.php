<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * copy() for compiled JIT/AOT modules (#9585, php-in-PHP).
 *
 * SSOT: {@see VmFs::copy()}
 * php-src: ext/standard/file.c — PHP_FUNCTION(copy)
 */
final class CopyJitHelper
{
    /** @return 0|1 ABI for __compiler_copy */
    public static function copyArgv(string $from, string $to): int
    {
        return VmFs::copy($from, $to) ? 1 : 0;
    }
}

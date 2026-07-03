<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * umask() for compiled JIT/AOT modules (#15497, php-in-PHP).
 *
 * SSOT: {@see umask_::execute()}
 * php-src: ext/standard/filestat.c — PHP_FUNCTION(umask)
 */
final class UmaskJitHelper
{
    public static function getArgv(): int
    {
        return (int) \umask();
    }

    public static function setArgv(int $mask): int
    {
        return (int) \umask($mask);
    }
}

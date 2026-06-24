<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * ftok() for compiled JIT/AOT modules (#9585, php-in-PHP).
 *
 * SSOT: {@see VmFtok::invoke()}
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(ftok)
 */
final class FtokJitHelper
{
    public static function ftokArgv(string $path, int $projId): int
    {
        return VmFtok::invoke($path, $projId);
    }
}

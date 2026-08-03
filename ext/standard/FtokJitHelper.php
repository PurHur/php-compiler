<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * ftok() host/VM helper (#9585). Thin AOT uses LLVM in {@see \PHPCompiler\JIT\Builtin\FtokRuntime}
 * (NestedJIT of this class stubs VmFtok under thin AOT — #27389).
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

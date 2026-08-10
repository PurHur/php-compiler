<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * tempnam() for compiled JIT/AOT modules (#15685, #29940, php-in-PHP).
 *
 * Leaf is `@tempnam` → NestedJIT whitelist {@see tempnam} →
 * {@see \PHPCompiler\JIT\Builtin\StringTempnam} → {@see JitTempnamKernel}
 * mkstemp leaf (peer FileGetContentsJitHelper #29833 / SysGetTempDirJitHelper #29433).
 * Null on failure so the ABI bridge returns null `__string__*` (call site boxes false).
 * php-src: ext/standard/file.c — php_tempnam / PHP_FUNCTION(tempnam)
 */
final class TempnamJitHelper
{
    /** @return string|null null when tempnam() fails */
    public static function resolveArgv(string $directory, string $prefix): ?string
    {
        $path = \tempnam($directory, $prefix);

        return \is_string($path) ? $path : null;
    }
}

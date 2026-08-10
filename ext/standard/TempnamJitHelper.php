<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * tempnam() for compiled JIT/AOT modules (#15685, #29940, php-in-PHP).
 *
 * Leaf is `@tempnam` → NestedJIT whitelist {@see tempnam} →
 * {@see \PHPCompiler\JIT\Builtin\StringTempnam} → {@see JitTempnamKernel}
 * mkstemp leaf (peer SysGetTempDirJitHelper #29433 / GetcwdJitHelper #29429).
 * Empty string on failure so the bridge can box to false.
 * php-src: ext/standard/file.c — php_tempnam / PHP_FUNCTION(tempnam)
 */
final class TempnamJitHelper
{
    /** @return string empty when tempnam() fails */
    public static function resolveArgv(string $directory, string $prefix): string
    {
        $path = \tempnam($directory, $prefix);

        return \is_string($path) ? $path : '';
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * JIT/AOT runtime helper for getcwd() — VM/algorithm reference (#10451).
 *
 * Thin AOT/JIT emit uses libc realpath(".") via {@see \PHPCompiler\JIT\Builtin\GetcwdJit}
 * (NestedJIT of this helper segfaults under user-script AOT — #26928).
 * SSOT: {@see VmGetcwdNative}
 * php-src: ext/standard/dir.c — php_get_current_dir()
 */
final class GetcwdJitHelper
{
    public static function resolveJit(): string
    {
        $cwd = VmGetcwdNative::resolve();

        return false === $cwd ? '' : $cwd;
    }
}

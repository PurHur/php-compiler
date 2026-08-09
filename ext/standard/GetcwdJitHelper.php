<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * getcwd() for compiled JIT/AOT modules (#29429, #25541, php-in-PHP).
 *
 * Leaf is `@getcwd` → NestedJIT whitelist {@see getcwd_} →
 * {@see \PHPCompiler\JIT\Builtin\GetcwdJit::invokeNestedLeaf} (getcwd(2); no VmGetcwdNative
 * pull in this TU — #26928 NestedJIT segfault root cause).
 * Empty string on failure so {@see JitGetcwd::boxed} can lower to false (#10451).
 * php-src: ext/standard/dir.c — PHP_FUNCTION(getcwd)
 */
final class GetcwdJitHelper
{
    public static function resolveJit(): string
    {
        $cwd = @\getcwd();

        return \is_string($cwd) ? $cwd : '';
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * JIT/AOT runtime helper for getcwd() — SSOT {@see VmGetcwdNative} (#10451).
 *
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

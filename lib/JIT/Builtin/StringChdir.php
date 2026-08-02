<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\ext\standard\JitChdirKernel;
use PHPLLVM\Value;

/**
 * JIT/AOT link for chdir() (#21147, #26928).
 *
 * Always libc {@see JitChdirKernel} — NestedJIT of ChdirJitHelper segfaults under thin
 * AOT after c:main_before_php (peer getcwd #26928 / getmypid #26944).
 * SSOT: {@see \PHPCompiler\ext\standard\VmFs::chdir()}.
 * php-src: ext/standard/dir.c — PHP_FUNCTION(chdir)
 */
final class StringChdir
{
    public static function ensureLinked(Context $context): void
    {
        // No module-level ABI — invoke emits chdir(2) at the call site.
        unset($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $path): Value
    {
        return JitChdirKernel::invoke($context, $path);
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitFsGlobKernel;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;

/**
 * glob()/scandir() dispatch — always {@see FsGlobVecRuntime} + NestedJIT libc leaf (#11515, #29986).
 *
 * Thin standalone AOT no longer forks always-on {@see JitFsGlobKernel} for user-facing
 * glob()/scandir() (tempnam #29940 / sys_get_temp_dir #29433 shape). NestedJIT of
 * {@see \PHPCompiler\ext\standard\FsGlobJitHelper} `@\glob`/`@\scandir` uses the libc
 * vec leaf only. GlobIterator/DirectoryIterator thin bridges call
 * {@see JitFsGlobKernel::implement} directly.
 *
 * php-src: ext/standard/dir.c — glob(), scandir()
 */
final class StringFsGlobVecJit
{
    public static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            JitFsGlobKernel::implement($context);

            return;
        }

        FsGlobVecRuntime::ensureLinked($context);
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitFsGlobKernel;
use PHPCompiler\JIT\Context;

/**
 * glob()/scandir() dispatch — NestedJIT helper (embed) vs libc kernel (thin AOT) (#11515, #27235).
 *
 * php-src: ext/standard/dir.c — glob(), scandir()
 */
final class StringFsGlobVecJit
{
    public static function implement(Context $context): void
    {
        if ($context->isThinStandaloneAotMain()) {
            JitFsGlobKernel::implementForThinAot($context);

            return;
        }

        FsGlobVecRuntime::ensureLinked($context);
    }
}

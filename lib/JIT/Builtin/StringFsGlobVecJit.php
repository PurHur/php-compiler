<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * glob()/scandir() dispatch — nested JIT compiles FsGlobJitHelper PHP (#11515, #12909).
 *
 * php-src: ext/standard/dir.c — glob(), scandir()
 */
final class StringFsGlobVecJit
{
    public static function implement(Context $context): void
    {
        FsGlobVecRuntime::ensureLinked($context);
    }
}

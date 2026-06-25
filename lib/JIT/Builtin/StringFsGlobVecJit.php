<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * glob()/scandir() vec dispatch — embed PHP helper vs standalone LLVM (#11515, #9585).
 *
 * php-src: ext/standard/dir.c — glob(), scandir()
 */
final class StringFsGlobVecJit
{
    public static function implement(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            FsGlobVecStandaloneLlvm::implement($context);

            return;
        }

        FsGlobVecRuntime::ensureLinked($context);
    }
}

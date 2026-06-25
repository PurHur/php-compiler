<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;

/** JIT/AOT proc_open dispatch — standalone LLVM quarantine + embed PHP bridge (#9408). */
final class ProcessOpenRuntime
{
    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            ProcessOpenStandaloneLlvm::implement($context);

            return;
        }

        ProcessOpenEmbedBridge::implement($context);
    }
}

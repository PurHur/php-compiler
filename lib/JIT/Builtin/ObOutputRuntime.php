<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;

/** JIT/AOT ob_* dispatch — standalone LLVM quarantine + JIT PHP bridge (#9268). */
final class ObOutputRuntime
{
    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            ObOutputStandaloneLlvm::implement($context);

            return;
        }

        ObOutputJitBridge::implement($context);
    }
}

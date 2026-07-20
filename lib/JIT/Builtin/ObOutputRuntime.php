<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** JIT/AOT ob_* dispatch — always NestedJIT ObOutputJitHelper via ObOutputJitBridge (#9268, #13571, #19422, #21066, #21469). */
final class ObOutputRuntime
{
    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    /** Lazy full ob stack when ob_* or exec stdout capture is lowered (#10492, #4914). */
    public static function ensureObStackLinked(Context $context): void
    {
        $append = $context->module->getNamedFunction('__phpc_ob_append_bytes');
        if (null !== $append && $append->countBasicBlocks() > 0) {
            return;
        }

        ObOutputJitBridge::implementObStack($context, true);
    }

    public static function implement(Context $context): void
    {
        ObOutputJitBridge::implement($context);
    }
}

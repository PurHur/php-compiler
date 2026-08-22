<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT ob_* dispatch — always NestedJIT ObOutputJitHelper via ObOutputJitBridge (#9268, #13571, #19422, #21066, #21469).
 *
 * Owns `__phpc_ob_*` / `__compiler_ob_gzhandler` module-locally (getNamedFunction first).
 * Do not re-add always-on empty decls in {@see Type} — leftover decls mint ob_start.1
 * (#31894 / #32122 / #33802). php-src: ext/standard/output.c
 */
final class ObOutputRuntime
{
    /**
     * Module-local empty decls when a bridge needs lookup before {@see ensureLinked} (#33802).
     */
    public static function declareObAbis(Context $context): void
    {
        ObOutput::registerExternals($context);
    }

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

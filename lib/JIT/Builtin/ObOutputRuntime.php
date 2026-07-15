<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;

/** JIT/AOT ob_* dispatch — standalone routes through ObOutputJitBridge (#9268, #13571). */
final class ObOutputRuntime
{
    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    /** Lazy full ob stack for user-script AOT when ob_* or exec stdout capture is lowered (#10492, #4914). */
    public static function ensureObStackLinked(Context $context): void
    {
        $append = $context->module->getNamedFunction('__phpc_ob_append_bytes');
        if (null !== $append && $append->countBasicBlocks() > 0) {
            self::ensureObReadApiLinked($context);

            return;
        }

        if (ObOutputUserScriptLlvm::shouldUse($context)) {
            ObOutputExecCaptureRuntime::ensureLinked($context);
            self::ensureObReadApiLinked($context);

            return;
        }

        ObOutputJitBridge::implementObStack($context, true);
    }

    private static function ensureObReadApiLinked(Context $context): void
    {
        if (!ObOutputUserScriptLlvm::shouldUse($context)) {
            return;
        }
        ObOutputExecCaptureRuntime::ensureReadApiLinked($context);
    }

    public static function implement(Context $context): void
    {
        ObOutputJitBridge::implement($context);
    }
}

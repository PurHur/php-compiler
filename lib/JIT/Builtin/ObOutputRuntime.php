<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** JIT/AOT ob_* dispatch via ObOutputJitHelper PHP bridges (#9268, #12951). */
final class ObOutputRuntime
{
    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        ObOutputJitBridge::implement($context);
    }
}

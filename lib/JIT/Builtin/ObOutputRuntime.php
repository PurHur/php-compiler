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

    public static function implement(Context $context): void
    {
        ObOutputJitBridge::implement($context);
    }
}

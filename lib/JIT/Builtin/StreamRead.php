<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** JIT/AOT stream read/position/lock helpers via StreamReadRuntime PHP (#5343, #12937). */
final class StreamRead
{
    public static function ensureLinked(Context $context): void
    {
        StreamReadJit::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        StreamReadRuntime::ensureStandaloneBodies($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}

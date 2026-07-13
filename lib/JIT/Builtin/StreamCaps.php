<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** JIT LLVM bodies for stream_isatty/is_local/supports capability probes (#5343). */
final class StreamCaps
{
    public static function ensureLinked(Context $context): void
    {
        StreamCapsJit::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        StreamCapsRuntime::implement($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** JIT LLVM bodies for fsync()/fdatasync() stream sync (#6062, #6813). */
final class StreamSync
{
    public static function ensureLinked(Context $context): void
    {
        StreamSyncJit::implement($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}

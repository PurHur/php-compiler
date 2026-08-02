<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitStreamSyncKernel;
use PHPCompiler\JIT\Context;

/** JIT LLVM bodies for fsync()/fdatasync() via libc after stream resolve (#6062, #6813, #19660, #26929). */
final class StreamSync
{
    public static function ensureLinked(Context $context): void
    {
        JitStreamSyncKernel::implement($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}

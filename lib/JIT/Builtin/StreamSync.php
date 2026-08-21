<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitStreamSyncKernel;
use PHPCompiler\JIT\Context;

/**
 * JIT LLVM bodies for fsync()/fdatasync() via libc after stream resolve (#6062, #6813, #19660, #26929, #33114).
 *
 * Owns `__compiler_fsync` (and peer fdatasync) ABI module-locally via
 * {@see JitStreamSyncKernel} (getNamedFunction first). Do not re-add empty always-on
 * shells in {@see Type} — leftover decls mint fsync.1 (#31894 / #32122).
 */
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

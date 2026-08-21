<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT LLVM bodies for stream_isatty/is_local/supports capability probes (#5343, #33148).
 *
 * Owns `__compiler_stream_is_local` (and peer stream_is_local_uri / stream_isatty) ABI
 * module-locally via {@see StreamCapsRuntime} / {@see \PHPCompiler\ext\standard\JitStreamCapsKernel}
 * (getNamedFunction first). Do not re-add empty always-on shells in {@see Type} — leftover
 * decls mint stream_is_local.1 (#31894 / #32122).
 */
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

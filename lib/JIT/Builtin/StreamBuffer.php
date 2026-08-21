<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT LLVM bodies for stream buffer controls (#5343 phase 4, #14462, #33127).
 *
 * Owns `__compiler_stream_set_chunk_size` ABI module-locally via
 * {@see StreamBufferRuntime} / {@see JitStreamBufferKernel} (getNamedFunction first).
 * Do not re-add empty always-on shells in {@see Type} — leftover decls mint
 * stream_set_chunk_size.1 (#31894 / #32122).
 */
final class StreamBuffer
{
    public static function ensureLinked(Context $context): void
    {
        StreamBufferRuntime::ensureLinked($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}

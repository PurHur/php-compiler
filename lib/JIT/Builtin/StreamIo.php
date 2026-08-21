<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT LLVM bodies for fopen/fread/fwrite/tmpfile stream I/O (#5343 phase 3, #4436, #33145).
 *
 * Owns `__compiler_stream_supports` (and peer stream I/O ABIs) module-locally via
 * {@see StreamIoRuntime} / {@see \PHPCompiler\ext\standard\JitStreamIoKernel}
 * (getNamedFunction first). Do not re-add empty always-on shells in {@see Type} — leftover
 * decls mint stream_supports.1 (#31894 / #32122).
 */
final class StreamIo
{
    public static function ensureLinked(Context $context): void
    {
        StreamIoJit::implement($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** JIT LLVM bodies for fopen/fread/fwrite/tmpfile stream I/O (#5343 phase 3, #4436). */
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

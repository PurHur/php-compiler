<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** JIT LLVM bodies for gzopen/gzwrite/gzread/gzclose stream API (#6168). */
final class GzStreamIo
{
    public static function ensureLinked(Context $context): void
    {
        GzStreamIoJit::implement($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}

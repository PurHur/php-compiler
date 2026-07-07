<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT link for bzopen/bzwrite/bzread/bzclose stream API (#17301).
 *
 * PHP lowering via {@see Bz2StreamRuntime} → {@see \PHPCompiler\ext\bz2\Bz2StreamJitHelper}.
 */
final class Bz2StreamIo
{
    public static function ensureLinked(Context $context): void
    {
        Bz2StreamRuntime::ensureLinked($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT link for gzopen/gzwrite/gzread/gzclose stream API (#6168, #13420).
 *
 * PHP lowering via {@see GzStreamRuntime} → {@see \PHPCompiler\ext\standard\GzStreamJitHelper}.
 */
final class GzStreamIo
{
    public static function ensureLinked(Context $context): void
    {
        GzStreamRuntime::ensureLinked($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}

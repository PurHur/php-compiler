<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT stream read/position/lock helpers via StreamReadRuntime PHP (#5343, #12937, #33155, #33164, #33166).
 *
 * Owns `__compiler_fgetc` / `__compiler_ftell` / `__compiler_ftruncate` (and peer flock/fpassthru/…)
 * ABI module-locally via {@see StreamReadRuntime} /
 * {@see \PHPCompiler\ext\standard\JitStreamReadBridgeKernel} (getNamedFunction first). Do not
 * re-add empty always-on shells in {@see Type} — leftover decls mint fgetc.1 / ftell.1 /
 * ftruncate.1 (#31894 / #32122).
 */
final class StreamRead
{
    public static function ensureLinked(Context $context): void
    {
        StreamReadJit::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        StreamReadRuntime::ensureStandaloneBodies($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}

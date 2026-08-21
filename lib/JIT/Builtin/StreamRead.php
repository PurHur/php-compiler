<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT stream read/position/lock helpers via StreamReadRuntime PHP (#5343, #12937, #33155, #33164, #33166, #33168, #33170, #33176, #33178, #33182).
 *
 * Owns `__compiler_ftell` / `__compiler_ftruncate` / `__compiler_fgetc` / `__compiler_fgets` /
 * `__compiler_stream_get_line` / `__compiler_fseek` / `__compiler_stream_get_contents` /
 * `__compiler_stream_copy_to_stream` (and peer flock/fpassthru/…) ABI module-locally via
 * {@see StreamReadRuntime} / {@see \PHPCompiler\ext\standard\JitStreamReadBridgeKernel}
 * (getNamedFunction first). Do not re-add empty always-on shells in {@see Type} — leftover decls
 * mint ftell.1 / ftruncate.1 / fgetc.1 / fgets.1 / stream_get_line.1 / fseek.1 /
 * stream_get_contents.1 / stream_copy_to_stream.1 (#31894 / #32122).
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

<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitStreamMetaKernel;
use PHPCompiler\JIT\Context;

/**
 * JIT LLVM bodies for stream_get_meta_data / stream_set_blocking / enable_crypto (#6007, #19678, #33154, #33157, #33159).
 *
 * Owns `__compiler_stream_get_meta_data` / `__compiler_stream_set_blocking` /
 * `__compiler_stream_enable_crypto` ABI module-locally via
 * {@see \PHPCompiler\ext\standard\JitStreamMetaKernel} /
 * {@see \PHPCompiler\ext\standard\JitStreamMetaThinAot} (getNamedFunction first). Do not
 * re-add empty always-on shells in {@see Type} — leftover decls mint stream_get_meta_data.1 /
 * stream_set_blocking.1 / stream_enable_crypto.1 (#31894 / #32122).
 */
final class StreamMeta
{
    public static function ensureLinked(Context $context): void
    {
        StreamModeRuntime::ensureLinked($context);
        JitStreamMetaKernel::implement($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}

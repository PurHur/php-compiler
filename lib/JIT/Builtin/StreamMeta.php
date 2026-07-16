<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitStreamMetaKernel;
use PHPCompiler\JIT\Context;

/** JIT LLVM bodies for stream_get_meta_data / stream_set_blocking (#6007, #19678). */
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

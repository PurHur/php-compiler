<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** JIT LLVM bodies for is_resource/fclose/feof/fflush stream lifecycle (#5343). */
final class StreamLifecycle
{
    public static function ensureLinked(Context $context): void
    {
        StreamLifecycleJit::implement($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** JIT/AOT link facade for shmop_* — ShmopJitHelper (#27408). */
final class StringShmop
{
    public static function ensureLinked(Context $context): void
    {
        ShmopRuntime::ensureLinked($context);
    }
}

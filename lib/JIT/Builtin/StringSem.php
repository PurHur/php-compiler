<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** JIT/AOT link facade for sem_* — SemJitHelper (#28431). */
final class StringSem
{
    public static function ensureLinked(Context $context): void
    {
        SemRuntime::ensureLinked($context);
    }
}

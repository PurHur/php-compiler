<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * getdate() helper link — AOT builds HT in {@see \PHPCompiler\ext\standard\JitGetdate} IR (#26900).
 *
 * NestedJIT / helper-runtime civil-math units segfault on user AOT init; keep this a no-op
 * so Type/String_ init does not pull those units. Host SSOT remains {@see \PHPCompiler\ext\standard\GetdateJitHelper}.
 */
final class StringGetdate
{
    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        // Intentionally empty — see class docblock (#26900).
    }
}

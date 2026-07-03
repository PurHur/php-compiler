<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringUmask;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** JIT/AOT helper for umask() via UmaskJitHelper PHP (#15497). */
final class JitUmask
{
    /** @return Value previous mask as native long */
    public static function invoke(Context $context, ?Value $maskLong): Value
    {
        return StringUmask::invoke($context, $maskLong);
    }
}

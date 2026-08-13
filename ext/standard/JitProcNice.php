<?php

declare(strict_types=1);

/**
 * JIT/AOT helper for proc_nice() via ProcNiceJitHelper PHP (#30615, #5181).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringProcNice;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

final class JitProcNice
{
    /** @return Value i1 — true when proc_nice succeeds */
    public static function invoke(Context $context, Value $priorityI64): Value
    {
        return StringProcNice::invoke($context, $priorityI64);
    }
}

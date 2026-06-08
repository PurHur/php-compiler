<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for getrusage() $mode — Z_PARAM_LONG (#4600, #6707). */
final class JitGetrusageArg
{
    public static function lowerMode(Context $context, JITVariable $arg): Value
    {
        return JitSleep::zParamLong($context, $arg, 'getrusage', 1, 'mode');
    }
}

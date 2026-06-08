<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for random_bytes() length operand — Z_PARAM_LONG (#4626, #6160). */
final class JitRandomBytesArg
{
    public static function lowerLength(Context $context, JITVariable $arg): Value
    {
        return JitSleep::zParamLong($context, $arg, 'random_bytes', 1, 'length');
    }
}

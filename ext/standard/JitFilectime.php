<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM lowering for filectime() via {@see JitStat::pathFileCtimeBoxed}. */
final class JitFilectime
{
    /** @return Value */
    public static function invoke(Context $context, Value $pathStr): Value
    {
        return JitStat::pathFileCtimeBoxed($context, $pathStr);
    }
}

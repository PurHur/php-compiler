<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM lowering for fileatime() via {@see JitStat::pathFileAtimeBoxed}. */
final class JitFileatime
{
    /** @return Value */
    public static function invoke(Context $context, Value $pathStr): Value
    {
        return JitStat::pathFileAtimeBoxed($context, $pathStr);
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM lowering for fileowner() via {@see JitStat::pathFileOwnerBoxed}. */
final class JitFileowner
{
    /** @return Value */
    public static function invoke(Context $context, Value $pathStr): Value
    {
        return JitStat::pathFileOwnerBoxed($context, $pathStr);
    }
}

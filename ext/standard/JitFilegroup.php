<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM lowering for filegroup() via {@see JitStat::pathFileGroupBoxed}. */
final class JitFilegroup
{
    /** @return Value */
    public static function invoke(Context $context, Value $pathStr): Value
    {
        return JitStat::pathFileGroupBoxed($context, $pathStr);
    }
}

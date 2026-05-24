<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM lowering for fileperms() via {@see JitStat::pathFilePermsBoxed}. */
final class JitFileperms
{
    /** @return Value */
    public static function invoke(Context $context, Value $pathStr): Value
    {
        return JitStat::pathFilePermsBoxed($context, $pathStr);
    }
}

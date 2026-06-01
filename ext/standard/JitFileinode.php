<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM lowering for fileinode() via {@see JitStat::pathFileInodeBoxed}. */
final class JitFileinode
{
    /** @return Value */
    public static function invoke(Context $context, Value $pathStr): Value
    {
        return JitStat::pathFileInodeBoxed($context, $pathStr);
    }
}

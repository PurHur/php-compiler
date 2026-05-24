<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM lowering for filetype() via {@see JitStat::pathFiletypeBoxed}. */
final class JitFiletype
{
    /** @return Value */
    public static function invoke(Context $context, Value $pathStr): Value
    {
        return JitStat::pathFiletypeBoxed($context, $pathStr);
    }
}

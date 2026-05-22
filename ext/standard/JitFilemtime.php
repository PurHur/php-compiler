<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM lowering for filemtime() via {@see JitStat::pathFileMtimeBoxed}. */
final class JitFilemtime
{
    /** @return Value __value__* (native long mtime, or boolean false on failure) */
    public static function invoke(Context $context, Value $pathStr): Value
    {
        return JitStat::pathFileMtimeBoxed($context, $pathStr);
    }
}

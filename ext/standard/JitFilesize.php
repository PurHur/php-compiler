<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM lowering for filesize() via {@see JitStat::pathFileSizeBoxed}. */
final class JitFilesize
{
    /** @return Value __value__* (native long size, or boolean false on failure) */
    public static function invoke(Context $context, Value $pathStr): Value
    {
        return JitStat::pathFileSizeBoxed($context, $pathStr);
    }
}

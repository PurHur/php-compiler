<?php

declare(strict_types=1);

/**
 * JIT/AOT helper for tempnam() via TempnamJitHelper PHP (#15685).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringTempnam;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

final class JitTempnam
{
    /** @return Value */
    public static function lowerDirectory(Context $context, JITVariable $arg): Value
    {
        // Z_PARAM_PATH: soft-null DEP+'' outside strict_types (#21595, reverts #20960);
        // empty directory → system temp in TempnamJitHelper / FsDirJitHelper.
        return JitStringBuiltinArg::lowerPath($context, $arg, 'tempnam', 0, 'directory');
    }

    /** @return Value */
    public static function invoke(Context $context, Value $dirStr, Value $prefixStr): Value
    {
        return StringTempnam::invoke($context, $dirStr, $prefixStr);
    }
}

<?php

declare(strict_types=1);

/**
 * JIT/AOT helper for rename() via RenameJitHelper PHP (#15533, #19215).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringRename;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

final class JitRename
{
    /** @return Value */
    public static function invoke(Context $context, Value $fromStr, Value $toStr): Value
    {
        return StringRename::invoke($context, $fromStr, $toStr);
    }
}

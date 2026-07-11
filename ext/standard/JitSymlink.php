<?php

declare(strict_types=1);

/**
 * JIT/AOT helper for symlink() via SymlinkJitHelper PHP (#15544).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringSymlink;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

final class JitSymlink
{
    /** @return Value */
    public static function invoke(Context $context, Value $targetStr, Value $linkStr): Value
    {
        return StringSymlink::invoke($context, $targetStr, $linkStr);
    }
}

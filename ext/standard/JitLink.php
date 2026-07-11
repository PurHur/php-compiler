<?php

declare(strict_types=1);

/**
 * JIT/AOT helper for link() via LinkJitHelper PHP (#15544).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringLink;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

final class JitLink
{
    /** @return Value */
    public static function invoke(Context $context, Value $targetStr, Value $linkStr): Value
    {
        return StringLink::invoke($context, $targetStr, $linkStr);
    }
}

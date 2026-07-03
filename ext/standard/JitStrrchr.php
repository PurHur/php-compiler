<?php

declare(strict_types=1);

/**
 * JIT/AOT helper for strrchr() via StrrchrJitHelper PHP (#15406).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringStrrchr;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

final class JitStrrchr
{
    /** @return Value */
    public static function find(Context $context, Value $haystack, Value $needle): Value
    {
        return StringStrrchr::invoke($context, $haystack, $needle);
    }
}

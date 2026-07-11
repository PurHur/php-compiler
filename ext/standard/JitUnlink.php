<?php

declare(strict_types=1);

/**
 * JIT/AOT helper for unlink() via UnlinkJitHelper PHP (#15471).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringUnlink;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

final class JitUnlink
{
    /** @return Value */
    public static function invoke(Context $context, Value $pathStr): Value
    {
        return StringUnlink::invoke($context, $pathStr);
    }
}

<?php

declare(strict_types=1);

/**
 * JIT lowering for nl2br() → __compiler_nl2br (C runtime; php-src string.c parity).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

final class JitNl2br
{
    /** @param Value $__string__* */
    public static function nl2br(Context $context, Value $strPtr, Value $useXhtmlI8): Value
    {
        return $context->builder->call(
            $context->lookupFunction('__compiler_nl2br'),
            $strPtr,
            $useXhtmlI8
        );
    }
}

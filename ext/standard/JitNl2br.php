<?php

declare(strict_types=1);

/**
 * JIT lowering for nl2br() → __string__nl2br (byte-for-byte parity with VmString::nl2br).
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
            $context->lookupFunction('__string__nl2br'),
            $strPtr,
            $useXhtmlI8
        );
    }
}

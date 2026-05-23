<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** JIT lowering for wordwrap() → __string__wordwrap. */
final class JitWordwrap
{
    public static function wrap(
        Context $context,
        Value $strPtr,
        Value $width,
        Value $break,
        Value $cutI8
    ): Value {
        return $context->builder->call(
            $context->lookupFunction('__string__wordwrap'),
            $strPtr,
            $width,
            $break,
            $cutI8
        );
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** JIT lowering for wordwrap() → __compiler_wordwrap (lib/AOT/runtime/compiler_wordwrap.c). */
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
            $context->lookupFunction('__compiler_wordwrap'),
            $strPtr,
            $width,
            $break,
            $cutI8
        );
    }
}

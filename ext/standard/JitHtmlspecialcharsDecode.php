<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM JIT helper for htmlspecialchars_decode() (ENT_QUOTES / ENT_COMPAT subset). */
final class JitHtmlspecialcharsDecode
{
    public static function decode(Context $context, Value $strPtr, Value $flags): Value
    {
        return $context->builder->call(
            $context->lookupFunction('__string__htmlspecialchars_decode'),
            $strPtr,
            $flags
        );
    }
}

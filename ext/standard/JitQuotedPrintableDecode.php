<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM JIT/AOT helper for quoted_printable_decode(). */
final class JitQuotedPrintableDecode
{
    public static function decode(Context $context, Value $str): Value
    {
        return $context->builder->call(
            $context->lookupFunction('__compiler_quoted_printable_decode'),
            $str
        );
    }
}

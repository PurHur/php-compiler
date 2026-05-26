<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM JIT helper for htmlentities() — reuses __string__htmlspecialchars (#2472). */
final class JitHtmlentities
{
    public static function escape(Context $context, Value $strPtr, Value $flags): Value
    {
        return $context->builder->call(
            $context->lookupFunction('__string__htmlspecialchars'),
            $strPtr,
            $flags
        );
    }
}

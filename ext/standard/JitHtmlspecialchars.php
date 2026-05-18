<?php

declare(strict_types=1);

/**
 * LLVM JIT helper for htmlspecialchars() with default ENT_QUOTES (UTF-8).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

final class JitHtmlspecialchars
{
    public static function escape(Context $context, Value $strPtr): Value
    {
        return $context->builder->call(
            $context->lookupFunction('__string__htmlspecialchars'),
            $strPtr
        );
    }
}

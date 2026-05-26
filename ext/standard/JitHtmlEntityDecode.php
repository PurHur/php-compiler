<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM JIT helper for html_entity_decode() — reuses __string__htmlspecialchars_decode (#2472). */
final class JitHtmlEntityDecode
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

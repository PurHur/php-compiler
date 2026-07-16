<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM lowering for user-script AOT htmlspecialchars — identity stub (#19389).
 *
 * Nested {@see HtmlspecialcharsJitHelper} breaks $_REQUEST reads under minimal
 * standalone init (#18974 / #16075); this kernel mirrors the former Builtin
 * identity stub from ext/ not lib/JIT/Builtin/. Full escape remains on the
 * nested-JIT path for non-defer builds.
 * php-src: ext/standard/html.c — PHP_FUNCTION(htmlspecialchars)
 */
final class JitHtmlspecialcharsKernel
{
    /**
     * Emit identity return of the input string; builder must be positioned at the entry block.
     *
     * ABI: __string__* (__string__* str, int64 flags)
     */
    public static function emitBody(Context $context, LlvmFunction $fn): void
    {
        $context->builder->returnValue($fn->getParam(0));
    }
}

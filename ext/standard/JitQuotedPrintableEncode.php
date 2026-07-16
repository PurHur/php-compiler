<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringQuotPrint;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM JIT/AOT helper for quoted_printable_encode() — VmString parity, no phpc_quot_print.c (#5225). */
final class JitQuotedPrintableEncode
{
    /**
     * @param callable(Context): Value $lower Z_PARAM_STR lowering after ensureLinked (#19283)
     */
    public static function encode(Context $context, JITVariable $subjectArg, callable $lower): Value
    {
        $literal = JitStringArg::compileTimeLiteral($subjectArg);
        if (null !== $literal) {
            return $context->builder->load(
                $context->constantStringFromString(VmString::quoted_printable_encode($literal))
            );
        }
        StringQuotPrint::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_quoted_printable_encode'),
            $lower($context)
        );
    }
}

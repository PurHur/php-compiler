<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM JIT/AOT string-arg lowering for quoted_printable_* (php-src ext/standard/quot_print.c, #4828). */
final class JitQuotPrint
{
    public static function lowerStringSubject(
        Context $context,
        JITVariable $arg,
        string $function,
        int $argIndex = 1,
        string $paramName = 'string'
    ): Value {
        if (JITVariable::TYPE_HASHTABLE === $arg->type
            || JITVariable::TYPE_OBJECT === $arg->type
            || JITVariable::TYPE_VALUE === $arg->type
        ) {
            return JitStringBuiltinArg::lower($context, $arg, $function, $argIndex, $paramName);
        }
        if ($context->callerStrictTypes) {
            JitInternalStrictArg::requireString($context, $arg, $function, $paramName, $argIndex);
        }

        return JitStringArg::lower($context, $arg, sprintf('%s() argument #%d', $function, $argIndex));
    }
}

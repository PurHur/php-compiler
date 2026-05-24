<?php

declare(strict_types=1);

/**
 * LLVM declaration for __compiler_parse_str — merge query string into a hashtable.
 */

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

final class StringParseStr
{
    public static function implement(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $void = $context->getTypeFromString('void');
        $fnType = $context->context->functionType($void, false, $htPtr, $strPtr);
        $fn = $context->module->addFunction('__compiler_parse_str', $fnType);
        $context->registerFunction('__compiler_parse_str', $fn);
    }
}

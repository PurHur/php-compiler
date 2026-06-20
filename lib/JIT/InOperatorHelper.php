<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\InOperatorRuntime;

/**
 * `$needle in $haystack` JIT lowering (#4716, #10172).
 *
 * SSOT: {@see \PHPCompiler\VM\InOperator}, {@see \PHPCompiler\VM\InOperatorJitHelper}
 */
final class InOperatorHelper
{
    public static function emitContains(Context $context, Variable $needle, Variable $haystack): Variable
    {
        InOperatorRuntime::emitGuardHaystackIsArray($context, $needle, $haystack);
        $strict = $context->constantFromBool(true);
        $found = ArrayBuiltinHelper::inArray($context, $needle, $haystack, $strict, 'in_op');

        return new Variable(
            $context,
            Variable::TYPE_NATIVE_BOOL,
            Variable::KIND_VALUE,
            $found
        );
    }
}

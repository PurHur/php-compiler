<?php

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringOffsetRuntime;
use PHPLLVM;

/**
 * Byte-level string offset read/write glue for JIT (issue #198, #3751, #10245).
 *
 * SSOT: {@see \PHPCompiler\VM\StringOffsetJitHelper} via {@see StringOffsetRuntime}
 */
final class StringOffsetHelper
{
    public const INCDEC_ERROR = \PHPCompiler\VM\StringOffsetJitHelper::INCDEC_ERROR;

    /**
     * Writable string offset lvalues store a char pointer, not {@see __string__}*.
     */
    public static function isWritableCharOffsetLvalue(Variable $var, Context $context): bool
    {
        if (Variable::KIND_VALUE !== $var->kind || Variable::TYPE_STRING !== $var->type) {
            return false;
        }
        $ty = $context->getStringFromType($var->value->typeOf());

        return 'i8*' === $ty || 'char*' === $ty;
    }

    public static function emitIncDecError(Context $context): void
    {
        StringOffsetRuntime::emitIncDecError($context);
    }

    public static function dimFetch(Context $context, PHPLLVM\Value $strSlot, Variable $dim): PHPLLVM\Value
    {
        return StringOffsetRuntime::dimFetch($context, $strSlot, $dim);
    }

    public static function normalizeOffset(Context $context, PHPLLVM\Value $index, PHPLLVM\Value $len): PHPLLVM\Value
    {
        return StringOffsetRuntime::normalizeOffset($context, $index, $len);
    }

    public static function dimAssign(Context $context, PHPLLVM\Value $charPtr, Variable $value): void
    {
        StringOffsetRuntime::dimAssign($context, $charPtr, $value);
    }

    /**
     * String offset read returns a length-1 {@see __string__} (PHP $s[$i] semantics).
     */
    public static function readAsString(Context $context, PHPLLVM\Value $charPtr): PHPLLVM\Value
    {
        return StringOffsetRuntime::readAsString($context, $charPtr);
    }
}

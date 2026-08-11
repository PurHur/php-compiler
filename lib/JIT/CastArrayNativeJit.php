<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\HashTableDuplicateRuntime;
use PHPCompiler\JIT\CastArrayShared;

/**
 * Native-type (array) cast lowering — extracted from CastHelper (#10244).
 *
 * SSOT: {@see \PHPCompiler\VM\CastSupport}
 */
final class CastArrayNativeJit
{
    public static function emit(Context $context, Variable $src): Variable
    {
        if (0 !== ($src->type & Variable::IS_NATIVE_ARRAY) || Variable::TYPE_HASHTABLE === $src->type) {
            $htSrc = 0 !== ($src->type & Variable::IS_NATIVE_ARRAY)
                ? HashTableHelper::materializeNativeArrayForCall($context, $src)
                : $context->helper->loadValue($src);
            $copy = HashTableDuplicateRuntime::duplicate($context, $htSrc);

            return new Variable($context, Variable::TYPE_HASHTABLE, Variable::KIND_VALUE, $copy);
        }
        if (Variable::TYPE_OBJECT === $src->type) {
            return CastArrayShared::emitObjectOperandToArray($context, $src, true);
        }
        if (Variable::TYPE_NULL === $src->type) {
            return HashTableHelper::emptyVariable($context);
        }
        if (Variable::TYPE_NATIVE_BOOL === $src->type) {
            return self::emitBool($context, $src);
        }
        if (
            Variable::TYPE_NATIVE_LONG === $src->type
            || Variable::TYPE_NATIVE_DOUBLE === $src->type
            || Variable::TYPE_STRING === $src->type
        ) {
            return CastArrayShared::wrapScalarInArray($context, $src);
        }

        throw new \LogicException(
            '(array) cast unsupported operand type in JIT: '.Variable::getStringType($src->type)
        );
    }

    private static function emitBool(Context $context, Variable $src): Variable
    {
        // Zend convert_to_array(IS_FALSE|IS_TRUE) — both wrap at index 0 (#30097).
        return CastArrayShared::wrapScalarInArray($context, $src);
    }
}

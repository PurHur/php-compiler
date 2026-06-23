<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\JitGetObjectVars;
use PHPCompiler\JIT\Builtin\CastArrayRuntime;

/**
 * Native-type (array) cast lowering — extracted from CastHelper (#10244).
 *
 * SSOT: {@see \PHPCompiler\VM\CastSupport}, {@see \PHPCompiler\VM\CastJitHelper}
 */
final class CastArrayNativeJit
{
    public static function emit(Context $context, Variable $src): Variable
    {
        if (0 !== ($src->type & Variable::IS_NATIVE_ARRAY) || Variable::TYPE_HASHTABLE === $src->type) {
            $htSrc = 0 !== ($src->type & Variable::IS_NATIVE_ARRAY)
                ? HashTableHelper::materializeNativeArrayForCall($context, $src)
                : $context->helper->loadValue($src);
            $copy = ArrayBuiltinHelper::duplicateHashtable($context, $htSrc);

            return new Variable($context, Variable::TYPE_HASHTABLE, Variable::KIND_VALUE, $copy);
        }
        if (Variable::TYPE_OBJECT === $src->type) {
            $valuePtr = JitGetObjectVars::invoke($context, $src, true);
            $array = HashTableHelper::emptyVariable($context);
            $array->value = $valuePtr;

            return $array;
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
        CastArrayRuntime::ensureLinked($context);
        $boolVal = $context->helper->loadValue($src);
        $yieldsEmpty = CastArrayRuntime::callBoolYieldsEmptyArray($context, $boolVal);
        $emptyBlock = BasicBlockHelper::append($context, 'cast_array_empty_bool');
        $wrapBlock = BasicBlockHelper::append($context, 'cast_array_wrap_bool');
        $mergeBlock = BasicBlockHelper::append($context, 'cast_array_bool_merge');
        $context->builder->branchIf($yieldsEmpty, $emptyBlock, $wrapBlock);
        $context->builder->positionAtEnd($emptyBlock);
        $empty = HashTableHelper::emptyVariable($context);
        $context->builder->branch($mergeBlock);
        $context->builder->positionAtEnd($wrapBlock);
        $wrapped = CastArrayShared::wrapScalarInArray($context, $src);
        $context->builder->branch($mergeBlock);
        $context->builder->positionAtEnd($mergeBlock);
        $phi = $context->builder->phi($empty->value->typeOf());
        $phi->addIncoming($empty->value, $emptyBlock);
        $phi->addIncoming($wrapped->value, $wrapBlock);
        $result = HashTableHelper::emptyVariable($context);
        $result->value = $phi;

        return $result;
    }
}

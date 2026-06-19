<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\ext\standard\JitGetObjectVars;
use PHPCompiler\JIT\Builtin\CastArrayRuntime;
use PHPCompiler\JIT\Builtin\CastArrayValueBoxJit;
use PHPCompiler\JIT\Builtin\CastObjectFromHashtableJit;
use PHPCompiler\JIT\Builtin\CastObjectValueBoxJit;
use PHPCompiler\OpCode;

/**
 * JIT lowering for (array)/(object)/(unset) casts (#4887, #10046).
 *
 * php-src: Zend/zend_operators.c / ext/standard/type.c
 * SSOT: {@see \PHPCompiler\VM\CastSupport}, {@see \PHPCompiler\VM\CastJitHelper}
 */
final class CastHelper
{
    public static function emitArrayCast(Context $context, Variable $src): Variable
    {
        CastArrayShared::ensureInsertBlock($context, 'cast_array_body');
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
        if (
            Variable::TYPE_NATIVE_LONG === $src->type
            || Variable::TYPE_NATIVE_DOUBLE === $src->type
            || Variable::TYPE_STRING === $src->type
        ) {
            return CastArrayShared::wrapScalarInArray($context, $src);
        }
        if (Variable::TYPE_VALUE === $src->type) {
            return CastArrayValueBoxJit::emit($context, $src);
        }

        throw new \LogicException(
            '(array) cast unsupported operand type in JIT: '.Variable::getStringType($src->type)
        );
    }

    public static function emitObjectCast(
        Context $context,
        Variable $src,
        Block $block,
        OpCode $op
    ): Variable {
        if (Variable::TYPE_OBJECT === $src->type) {
            return new Variable(
                $context,
                Variable::TYPE_OBJECT,
                Variable::KIND_VALUE,
                $context->helper->loadValue($src)
            );
        }
        if (Variable::TYPE_HASHTABLE === $src->type) {
            return CastObjectFromHashtableJit::emit($context, $src, $block, $op);
        }
        if (Variable::TYPE_VALUE === $src->type) {
            return CastObjectValueBoxJit::emit($context, $src, $block, $op);
        }

        throw new \LogicException(
            '(object) cast unsupported operand type in JIT: '.Variable::getStringType($src->type)
        );
    }

    public static function emitUnsetCast(Context $context, Variable $src): Variable
    {
        if (null !== $src->valueBoxAliasPtr) {
            $context->builder->call(
                $context->lookupFunction('__value__writeNull'),
                JitValueBox::normalizeValuePtr($context, $src->valueBoxAliasPtr)
            );
        }
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitGetObjectVars;
use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\CastArrayShared;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;

/**
 * (array) cast from boxed TYPE_VALUE operands (#10046).
 *
 * php-src: Zend/zend_operators.c — convert_to_array
 */
final class CastArrayValueBoxJit
{
    public static function emit(Context $context, Variable $src): Variable
    {
        $valuePtr = \PHPCompiler\JIT\JitValueBox::valuePtrFromVariable($context, $src);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');

        $isArray = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_ARRAY, false)
        );
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_OBJECT, false)
        );
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_NULL, false)
        );

        $arrayBlock = BasicBlockHelper::append($context, 'cast_array_vb_ht');
        $objectBlock = BasicBlockHelper::append($context, 'cast_array_vb_obj');
        $nullBlock = BasicBlockHelper::append($context, 'cast_array_vb_null');
        $scalarBlock = BasicBlockHelper::append($context, 'cast_array_vb_scalar');
        $mergeBlock = BasicBlockHelper::append($context, 'cast_array_vb_merge');
        $doneBlock = BasicBlockHelper::append($context, 'cast_array_vb_done');

        $context->builder->branchIf($isArray, $arrayBlock, $checkObj = BasicBlockHelper::append($context, 'cast_array_vb_chk_obj'));
        $context->builder->positionAtEnd($checkObj);
        $context->builder->branchIf($isObject, $objectBlock, $checkNull = BasicBlockHelper::append($context, 'cast_array_vb_chk_null'));
        $context->builder->positionAtEnd($checkNull);
        $context->builder->branchIf($isNull, $nullBlock, $scalarBlock);

        $context->builder->positionAtEnd($arrayBlock);
        $ht = $context->builder->call($context->lookupFunction('__value__readHashtable'), $valuePtr);
        $copy = ArrayBuiltinHelper::duplicateHashtable($context, $ht);
        $arrayFromHt = HashTableHelper::emptyVariable($context);
        HashTableHelper::storeHashtableInArrayVariable($context, $arrayFromHt, $copy);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($objectBlock);
        $objCast = JitGetObjectVars::invoke($context, $src, true);
        $arrayFromObj = HashTableHelper::emptyVariable($context);
        $arrayFromObj->value = $objCast;
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($nullBlock);
        $empty = HashTableHelper::emptyVariable($context);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($scalarBlock);
        $wrapped = CastArrayShared::wrapScalarInArray($context, $src);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);
        $phi = $context->builder->phi($arrayFromHt->value->typeOf());
        $phi->addIncoming($arrayFromHt->value, $arrayBlock);
        $phi->addIncoming($arrayFromObj->value, $objectBlock);
        $phi->addIncoming($empty->value, $nullBlock);
        $phi->addIncoming($wrapped->value, $scalarBlock);
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($doneBlock);
        $result = HashTableHelper::emptyVariable($context);
        $result->value = $phi;

        return $result;
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\HashTableDuplicateRuntime;
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
        // Mask IS_REFCOUNTED — object/array boxes may store kind|0x80 (#21921 / #33863).
        // Arrays: writers may store JIT TYPE_HASHTABLE (135→7) or VM TYPE_ARRAY (6)
        // (peer HashTable::readHashtable / #26977 / #26367).
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));

        $isJitHt = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_HASHTABLE & 0x7f, false)
        );
        $isVmArray = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(VmVariable::TYPE_ARRAY, false)
        );
        $isArray = $context->builder->or($isJitHt, $isVmArray);
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(VmVariable::TYPE_OBJECT, false)
        );
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
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
        $copy = HashTableDuplicateRuntime::duplicate($context, $ht);
        // Do not emptyVariable()+store — TYPE_HASHTABLE store is a no-op (#33863).
        $arrayFromHt = new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            $copy
        );
        $arrayEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($objectBlock);
        $arrayFromObj = CastArrayShared::emitObjectOperandToArray($context, $src, true);
        // Nested SPL/gov/singleton paths leave insert off $objectBlock (#33863).
        $objectEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($nullBlock);
        $empty = HashTableHelper::emptyVariable($context);
        $nullEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($scalarBlock);
        $wrapped = CastArrayShared::wrapScalarInArray($context, $src);
        $scalarEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);
        $phi = $context->builder->phi($arrayFromHt->value->typeOf());
        $phi->addIncoming($arrayFromHt->value, $arrayEnd);
        $phi->addIncoming($arrayFromObj->value, $objectEnd);
        $phi->addIncoming($empty->value, $nullEnd);
        $phi->addIncoming($wrapped->value, $scalarEnd);
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($doneBlock);
        $result = HashTableHelper::emptyVariable($context);
        $result->value = $phi;

        return $result;
    }
}

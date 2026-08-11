<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\Block;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\OpCode;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;

/**
 * (object) cast from boxed TYPE_VALUE operands (#10046).
 *
 * php-src: Zend/zend_operators.c — cast_object
 */
final class CastObjectValueBoxJit
{
    public static function emit(
        Context $context,
        Variable $src,
        Block $block,
        OpCode $op
    ): Variable {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $src);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');

        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_OBJECT, false)
        );
        $isEnumCase = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_ENUM_CASE, false)
        );
        $isArray = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_ARRAY, false)
        );

        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_NULL, false)
        );

        $objectBlock = BasicBlockHelper::append($context, 'cast_object_vb_obj');
        $arrayBlock = BasicBlockHelper::append($context, 'cast_object_vb_ht');
        $nullBlock = BasicBlockHelper::append($context, 'cast_object_vb_null');
        $scalarBlock = BasicBlockHelper::append($context, 'cast_object_vb_scalar');
        $mergeBlock = BasicBlockHelper::append($context, 'cast_object_vb_merge');
        $doneBlock = BasicBlockHelper::append($context, 'cast_object_vb_done');

        $checkEnum = BasicBlockHelper::append($context, 'cast_object_vb_chk_enum');
        $context->builder->branchIf($isObject, $objectBlock, $checkEnum);
        $context->builder->positionAtEnd($checkEnum);
        $context->builder->branchIf($isEnumCase, $objectBlock, $checkArray = BasicBlockHelper::append($context, 'cast_object_vb_chk_ht'));
        $context->builder->positionAtEnd($checkArray);
        $context->builder->branchIf($isArray, $arrayBlock, $checkNull = BasicBlockHelper::append($context, 'cast_object_vb_chk_null'));
        $context->builder->positionAtEnd($checkNull);
        $context->builder->branchIf($isNull, $nullBlock, $scalarBlock);

        $context->builder->positionAtEnd($objectBlock);
        $obj = $context->builder->call($context->lookupFunction('__value__readObject'), $valuePtr);
        $objectResult = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $obj);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($arrayBlock);
        $htVar = new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            $context->builder->call($context->lookupFunction('__value__readHashtable'), $valuePtr)
        );
        $arrayResult = CastObjectFromHashtableJit::emit($context, $htVar, $block, $op);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($nullBlock);
        $nullResult = CastObjectFromHashtableJit::emitEmptyStdClass($context);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($scalarBlock);
        $scalarResult = CastObjectFromHashtableJit::emitScalarStdClass($context, $src);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);
        $phi = $context->builder->phi($objectResult->value->typeOf());
        $phi->addIncoming($objectResult->value, $objectBlock);
        $phi->addIncoming($arrayResult->value, $arrayBlock);
        $phi->addIncoming($nullResult->value, $nullBlock);
        $phi->addIncoming($scalarResult->value, $scalarBlock);
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($doneBlock);
        $result = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $phi);

        return $result;
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\Block;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectBuiltin;
use PHPCompiler\OpCode;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;

/**
 * (object) cast from boxed TYPE_VALUE operands (#10046).
 *
 * php-src: Zend/zend_operators.c — cast_object (#30098, #30793).
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
        // Resource wrappers are TYPE_OBJECT — wrap as stdClass.scalar like Zend IS_RESOURCE.
        // Copy the original value-box ($src) so thin AOT keeps the same handle representation
        // fopen produced (isset/is_resource parity) (#30793).
        $objectResult = self::emitObjectOrResourceWrap($context, $src, $valuePtr);
        $objectEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($arrayBlock);
        $htVar = new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            $context->builder->call($context->lookupFunction('__value__readHashtable'), $valuePtr)
        );
        $arrayResult = CastObjectFromHashtableJit::emit($context, $htVar, $block, $op);
        $arrayEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($nullBlock);
        $nullResult = CastObjectFromHashtableJit::emitEmptyStdClass($context);
        $nullEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($scalarBlock);
        $scalarResult = CastObjectFromHashtableJit::emitScalarStdClass($context, $src);
        $scalarEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);
        $phi = $context->builder->phi($objectResult->value->typeOf());
        $phi->addIncoming($objectResult->value, $objectEnd);
        $phi->addIncoming($arrayResult->value, $arrayEnd);
        $phi->addIncoming($nullResult->value, $nullEnd);
        $phi->addIncoming($scalarResult->value, $scalarEnd);
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($doneBlock);
        $result = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $phi);

        return $result;
    }

    /**
     * Real objects keep identity; Resource class wraps via stdClass.scalar from $src box (#30793).
     */
    private static function emitObjectOrResourceWrap(
        Context $context,
        Variable $src,
        \PHPLLVM\Value $valuePtr
    ): Variable {
        $resourceClassId = self::resourceClassIdIfRegistered($context);
        $obj = $context->builder->call($context->lookupFunction('__value__readObject'), $valuePtr);
        if (null === $resourceClassId) {
            return new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $obj);
        }

        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($obj, $objMap['class_id'])
        );
        $isResource = $context->builder->icmp(
            Builder::INT_EQ,
            $classId,
            $context->constantFromInteger($resourceClassId, 'int64')
        );

        $resourceBlock = BasicBlockHelper::append($context, 'cast_object_vb_res_wrap');
        $plainBlock = BasicBlockHelper::append($context, 'cast_object_vb_res_plain');
        $mergeBlock = BasicBlockHelper::append($context, 'cast_object_vb_res_merge');
        $doneBlock = BasicBlockHelper::append($context, 'cast_object_vb_res_done');

        $context->builder->branchIf($isResource, $resourceBlock, $plainBlock);

        $context->builder->positionAtEnd($resourceBlock);
        // Keep original box contents (handle + flags), not a re-boxed object* (#30793 AOT).
        $wrapped = CastObjectFromHashtableJit::emitScalarStdClass($context, $src);
        $resourceEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($plainBlock);
        $plain = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $obj);
        $plainEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);
        $phi = $context->builder->phi($wrapped->value->typeOf());
        $phi->addIncoming($wrapped->value, $resourceEnd);
        $phi->addIncoming($plain->value, $plainEnd);
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($doneBlock);

        return new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $phi);
    }

    private static function resourceClassIdIfRegistered(Context $context): ?int
    {
        $object = $context->type->object;
        if (!$object instanceof ObjectBuiltin) {
            return null;
        }

        return $object->lookup('resource');
    }
}

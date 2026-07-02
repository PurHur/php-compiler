<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\JitGetObjectVars;
use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectBuiltin;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** Shared (array) cast helpers for CastHelper + CastArrayValueBoxJit (#10046). */
final class CastArrayShared
{
    public static function ensureInsertBlock(Context $context, string $label): void
    {
        $insert = $context->builder->getInsertBlock();
        if (null === $insert) {
            throw new \LogicException('JIT cast lowering requires an active basic block');
        }
        if (null !== $insert->getTerminator()) {
            $next = BasicBlockHelper::append($context, $label);
            $context->builder->positionAtEnd($insert);
            $context->builder->branch($next);
            $context->builder->positionAtEnd($next);
        }
    }

    public static function wrapScalarInArray(Context $context, Variable $src): Variable
    {
        $ht = HashTableHelper::alloc($context);
        $zero = $context->getTypeFromString('size_t')->constInt(0, false);
        if (Variable::TYPE_VALUE === $src->type) {
            $boxed = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VALUE, $src->value);
            HashTableHelper::setAtIndex($context, $ht, $zero, $boxed);
        } else {
            HashTableHelper::setAtIndex($context, $ht, $zero, $src);
        }
        $array = HashTableHelper::emptyVariable($context);
        HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);

        return $array;
    }

    /** Zend convert_to_array: Resource pseudo-class embeds the resource zval at index 0 (#15012, #15013). */
    public static function emitObjectOperandToArray(Context $context, Variable $src, bool $mangledKeys = true): Variable
    {
        $resourceClassId = self::resourceClassIdIfRegistered($context);
        if (null === $resourceClassId) {
            return self::emitGetObjectVarsArray($context, $src, $mangledKeys);
        }

        $objPtr = self::loadObjectPtrFromOperand($context, $src);
        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($objPtr, $objMap['class_id'])
        );
        $isResource = $context->builder->icmp(
            Builder::INT_EQ,
            $classId,
            $context->constantFromInteger($resourceClassId, 'int64')
        );

        $resourceBlock = BasicBlockHelper::append($context, 'cast_array_obj_res');
        $plainBlock = BasicBlockHelper::append($context, 'cast_array_obj_plain');
        $mergeBlock = BasicBlockHelper::append($context, 'cast_array_obj_merge');
        $doneBlock = BasicBlockHelper::append($context, 'cast_array_obj_done');

        $context->builder->branchIf($isResource, $resourceBlock, $plainBlock);

        $context->builder->positionAtEnd($resourceBlock);
        $wrapped = self::wrapScalarInArray($context, $src);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($plainBlock);
        $fromObj = self::emitGetObjectVarsArray($context, $src, $mangledKeys);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);
        $phi = $context->builder->phi($wrapped->value->typeOf());
        $phi->addIncoming($wrapped->value, $resourceBlock);
        $phi->addIncoming($fromObj->value, $plainBlock);
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($doneBlock);
        $result = HashTableHelper::emptyVariable($context);
        $result->value = $phi;

        return $result;
    }

    private static function resourceClassIdIfRegistered(Context $context): ?int
    {
        $object = $context->type->object;
        if (!$object instanceof ObjectBuiltin) {
            return null;
        }

        return $object->lookup('resource');
    }

    private static function loadObjectPtrFromOperand(Context $context, Variable $src): Value
    {
        if (Variable::TYPE_OBJECT === $src->type) {
            return $context->helper->loadValue($src);
        }
        if (Variable::TYPE_VALUE === $src->type) {
            $valuePtr = JitValueBox::valuePtrFromVariable($context, $src);

            return $context->builder->call($context->lookupFunction('__value__readObject'), $valuePtr);
        }

        throw new \LogicException(
            'object (array) cast requires object or boxed value operand: '.Variable::getStringType($src->type)
        );
    }

    private static function emitGetObjectVarsArray(Context $context, Variable $src, bool $mangledKeys): Variable
    {
        $objCast = JitGetObjectVars::invoke($context, $src, $mangledKeys);
        $arrayFromObj = HashTableHelper::emptyVariable($context);
        $arrayFromObj->value = $objCast;

        return $arrayFromObj;
    }
}

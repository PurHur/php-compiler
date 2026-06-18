<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPCfg\Op;
use PHPTypes\Type;
use PHPCompiler\Block;
use PHPCompiler\ext\standard\JitGetObjectVars;
use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectBuiltin;
use PHPCompiler\OpCode;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;

/**
 * JIT lowering for (array)/(object)/(unset) casts (#4887, zend_operators.c / CastSupport).
 */
final class CastHelper
{
    private static function ensureInsertBlock(Context $context, string $label): void
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

    public static function emitArrayCast(Context $context, Variable $src): Variable
    {
        self::ensureInsertBlock($context, 'cast_array_body');
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
            $isFalse = $context->builder->icmp(
                Builder::INT_EQ,
                $context->helper->loadValue($src),
                $context->getTypeFromString('int1')->constInt(0, false)
            );
            $emptyBlock = BasicBlockHelper::append($context, 'cast_array_empty_bool');
            $wrapBlock = BasicBlockHelper::append($context, 'cast_array_wrap_bool');
            $mergeBlock = BasicBlockHelper::append($context, 'cast_array_bool_merge');
            $context->builder->branchIf($isFalse, $emptyBlock, $wrapBlock);
            $context->builder->positionAtEnd($emptyBlock);
            $empty = HashTableHelper::emptyVariable($context);
            $context->builder->branch($mergeBlock);
            $context->builder->positionAtEnd($wrapBlock);
            $wrapped = self::wrapScalarInArray($context, $src);
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
            return self::wrapScalarInArray($context, $src);
        }
        if (Variable::TYPE_VALUE === $src->type) {
            return self::emitArrayCastFromValueBox($context, $src);
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
            return self::emitHashtableToStdClass($context, $src, $block, $op);
        }
        if (Variable::TYPE_VALUE === $src->type) {
            return self::emitObjectCastFromValueBox($context, $src, $block, $op);
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

    private static function emitObjectCastFromValueBox(
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

        $objectBlock = BasicBlockHelper::append($context, 'cast_object_vb_obj');
        $arrayBlock = BasicBlockHelper::append($context, 'cast_object_vb_ht');
        $emptyBlock = BasicBlockHelper::append($context, 'cast_object_vb_empty');
        $mergeBlock = BasicBlockHelper::append($context, 'cast_object_vb_merge');
        $doneBlock = BasicBlockHelper::append($context, 'cast_object_vb_done');

        $checkEnum = BasicBlockHelper::append($context, 'cast_object_vb_chk_enum');
        $context->builder->branchIf($isObject, $objectBlock, $checkEnum);
        $context->builder->positionAtEnd($checkEnum);
        $context->builder->branchIf($isEnumCase, $objectBlock, $checkArray = BasicBlockHelper::append($context, 'cast_object_vb_chk_ht'));
        $context->builder->positionAtEnd($checkArray);
        $context->builder->branchIf($isArray, $arrayBlock, $emptyBlock);

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
        $arrayResult = self::emitHashtableToStdClass($context, $htVar, $block, $op);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyResult = self::emitEmptyStdClass($context);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);
        $phi = $context->builder->phi($objectResult->value->typeOf());
        $phi->addIncoming($objectResult->value, $objectBlock);
        $phi->addIncoming($arrayResult->value, $arrayBlock);
        $phi->addIncoming($emptyResult->value, $emptyBlock);
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($doneBlock);
        $result = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $phi);

        return $result;
    }

    private static function emitEmptyStdClass(Context $context): Variable
    {
        self::ensureInsertBlock($context, 'cast_object_empty_stdclass');
        /** @var ObjectBuiltin $object */
        $object = $context->type->object;
        $classId = $object->lookup('stdClass');
        $objVal = $object->allocate($classId);
        $object->markObjectConstructed($objVal);

        return new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $objVal);
    }

    private static function emitArrayCastFromValueBox(Context $context, Variable $src): Variable
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $src);
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
        $wrapped = self::wrapScalarInArray($context, $src);
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

    private static function wrapScalarInArray(Context $context, Variable $src): Variable
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

    private static function emitHashtableToStdClass(
        Context $context,
        Variable $src,
        Block $block,
        OpCode $op
    ): Variable {
        self::ensureInsertBlock($context, 'cast_object_body');
        $savedInsert = self::captureInsertBlock($context);
        /** @var ObjectBuiltin $object */
        $object = $context->type->object;
        $classId = $object->lookup('stdClass');
        $srcOp = $block->getOperand($op->arg2);
        $literalKeys = self::literalArrayKeysFromBlock($block, $srcOp);
        foreach ($literalKeys as $key) {
            if ($object->hasProperty($classId, $key)) {
                continue;
            }
            $object->defineProperty($classId, $key, Variable::TYPE_VALUE);
        }
        // Object_::allocate() may link GC runtime helpers and clear the builder insert point.
        $objVal = $object->allocate($classId);
        self::restoreInsertBlock($context, $savedInsert);
        $object->markObjectConstructed($objVal);
        $ht = $context->helper->loadValue($src);
        $className = 'stdClass';
        foreach ($object->instancePropertySets($classId) as $propset) {
            $propName = $propset[1];
            $keyStr = $context->builder->load($context->constantStringFromString($propName));
            $valEntry = $context->builder->call(
                $context->lookupFunction('__hashtable__readStringKeyValue'),
                $ht,
                $keyStr
            );
            $slot = $object->propertySlotFor($objVal, $className, $propName);
            $context->builder->call(
                $context->lookupFunction('__object__load_value_slot'),
                $slot,
                $valEntry
            );
        }
        self::restoreInsertBlock($context, $savedInsert);

        return new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $objVal);
    }

    private static function captureInsertBlock(Context $context): ?BasicBlock
    {
        try {
            return $context->builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function restoreInsertBlock(Context $context, ?BasicBlock $block): void
    {
        if (null !== $block) {
            $context->builder->positionAtEnd($block);

            return;
        }
        $context->builder->clearInsertionPosition();
    }

    /**
     * @return list<string>
     */
    private static function literalArrayKeysFromBlock(Block $block, Operand $srcOp): array
    {
        $keys = self::literalArrayKeysFromOperand($srcOp);
        if ([] !== $keys) {
            return array_values(array_unique($keys));
        }
        $arraySlot = self::operandSlot($block, $srcOp);
        if (null === $arraySlot) {
            return [];
        }
        $nextList = 0;
        foreach ($block->opCodes as $blockOp) {
            if (OpCode::TYPE_INIT_ARRAY === $blockOp->type && $blockOp->arg1 === $arraySlot) {
                if (null !== $blockOp->arg3) {
                    $key = self::literalKeyFromSlot($block, $blockOp->arg3);
                    if (null !== $key) {
                        $keys[] = $key;
                    }
                } elseif (null !== $blockOp->arg2) {
                    $keys[] = (string) $nextList;
                    ++$nextList;
                }
                continue;
            }
            if (OpCode::TYPE_ADD_ARRAY_ELEMENT === $blockOp->type && $blockOp->arg1 === $arraySlot) {
                if (null !== $blockOp->arg3) {
                    $key = self::literalKeyFromSlot($block, $blockOp->arg3);
                    if (null !== $key) {
                        $keys[] = $key;
                    }
                } else {
                    $keys[] = (string) $nextList;
                    ++$nextList;
                }
            }
        }

        return array_values(array_unique($keys));
    }

    private static function operandSlot(Block $block, Operand $target): ?int
    {
        foreach ($block->scope as $operand => $slot) {
            if ($operand === $target) {
                return $slot;
            }
        }

        return null;
    }

    private static function literalKeyFromSlot(Block $block, int $keySlot): ?string
    {
        if (!isset($block->constants[$keySlot])) {
            return null;
        }
        $constant = $block->constants[$keySlot];
        if (VmVariable::TYPE_STRING === $constant->type) {
            return $constant->toString();
        }
        if (VmVariable::TYPE_INTEGER === $constant->type) {
            return (string) $constant->toInt();
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function literalArrayKeysFromOperand(Operand $operand): array
    {
        $keys = [];
        $seen = new \SplObjectStorage();
        $stack = [$operand];
        while ([] !== $stack) {
            $current = array_pop($stack);
            if (!$current instanceof Operand || $seen->contains($current)) {
                continue;
            }
            $seen->attach($current);
            foreach ($current->ops as $exprOp) {
                if ($exprOp instanceof Op\Expr\Array_) {
                    foreach ($exprOp->keys as $i => $key) {
                        if ($key instanceof Literal && Type::TYPE_STRING === $key->type->type) {
                            $keys[] = (string) $key->value;
                        } elseif ($key instanceof Literal && Type::TYPE_LONG === $key->type->type) {
                            $keys[] = (string) $key->value;
                        } elseif ($key instanceof Operand\NullOperand) {
                            $keys[] = (string) $i;
                        }
                    }
                } elseif ($exprOp instanceof Op\Expr\Cast\Object_
                    || $exprOp instanceof Op\Expr\Cast\Array_
                    || $exprOp instanceof Op\Expr\Cast\Bool_
                    || $exprOp instanceof Op\Expr\Cast\Int_
                    || $exprOp instanceof Op\Expr\Cast\Double
                    || $exprOp instanceof Op\Expr\Cast\String_
                    || $exprOp instanceof Op\Expr\Cast\Unset_
                    || $exprOp instanceof Op\Expr\Cast\Void_) {
                    if ($exprOp->expr instanceof Operand) {
                        $stack[] = $exprOp->expr;
                    }
                } elseif ($exprOp instanceof Op\Expr\Assign && $exprOp->expr instanceof Operand) {
                    $stack[] = $exprOp->expr;
                }
            }
        }

        return $keys;
    }
}

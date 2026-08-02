<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\JitGetObjectVars;
use PHPCompiler\JIT\Builtin\CastArrayRuntime;
use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectBuiltin;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** Shared (array) cast helpers for CastHelper + CastArrayValueBoxJit (#10046, #19631). */
final class CastArrayShared
{
    public static function ensureInsertBlock(Context $context, string $label): void
    {
        $insert = $context->builder->getInsertBlock();
        if (null === $insert) {
            throw new \LogicException('JIT cast lowering requires an active basic block');
        }
        // Sealed insert: move to a fresh block — never branch from a block that already
        // has a terminator (creates "terminator in the middle", #26818).
        if (null !== $insert->getTerminator()) {
            BasicBlockHelper::ensureOpenInsertBlock($context, $label);
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

    /** Zend convert_to_array(IS_RESOURCE) — array(0 => resource zval) (#15012, #15013). */
    public static function wrapResourceInArray(Context $context, Variable $src): Variable
    {
        return self::wrapScalarInArray($context, $src);
    }

    /**
     * Zend convert_to_array: Resource/Closure singleton; ArrayObject backing (#19631);
     * else get_object_vars mangling.
     */
    public static function emitObjectOperandToArray(Context $context, Variable $src, bool $mangledKeys = true): Variable
    {
        $resourceClassId = self::resourceClassIdIfRegistered($context);
        $closureClassId = self::closureClassIdIfRegistered($context);
        if (null === $resourceClassId && null === $closureClassId) {
            return self::emitSplOrGetObjectVars($context, $src, $mangledKeys);
        }

        $objPtr = self::loadObjectPtrFromOperand($context, $src);
        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($objPtr, $objMap['class_id'])
        );
        $isSingleton = null;
        if (null !== $resourceClassId) {
            $isResource = $context->builder->icmp(
                Builder::INT_EQ,
                $classId,
                $context->constantFromInteger($resourceClassId, 'int64')
            );
            $isSingleton = $isResource;
        }
        if (null !== $closureClassId) {
            $isClosure = $context->builder->icmp(
                Builder::INT_EQ,
                $classId,
                $context->constantFromInteger($closureClassId, 'int64')
            );
            $isSingleton = null !== $isSingleton
                ? $context->builder->or($isSingleton, $isClosure)
                : $isClosure;
        }

        $singletonBlock = BasicBlockHelper::append($context, 'cast_array_obj_singleton');
        $plainBlock = BasicBlockHelper::append($context, 'cast_array_obj_plain');
        $mergeBlock = BasicBlockHelper::append($context, 'cast_array_obj_merge');
        $doneBlock = BasicBlockHelper::append($context, 'cast_array_obj_done');

        $context->builder->branchIf($isSingleton, $singletonBlock, $plainBlock);

        $context->builder->positionAtEnd($singletonBlock);
        $wrapped = self::wrapResourceInArray($context, $src);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($plainBlock);
        $fromObj = self::emitSplOrGetObjectVars($context, $src, $mangledKeys);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);
        $phi = $context->builder->phi($wrapped->value->typeOf());
        $phi->addIncoming($wrapped->value, $singletonBlock);
        $phi->addIncoming($fromObj->value, $plainBlock);
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($doneBlock);
        $result = HashTableHelper::emptyVariable($context);
        $result->value = $phi;

        return $result;
    }

    private static function emitSplOrGetObjectVars(Context $context, Variable $src, bool $mangledKeys): Variable
    {
        $operandPtr = self::operandToValueBox($context, $src);
        $splBoxed = CastArrayRuntime::callTrySplArrayCast($context, $operandPtr);
        $typeByte = $context->builder->load(
            $context->builder->structGep($splBoxed, $context->structFieldMap['__value__']['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $isArray = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_ARRAY, false)
        );

        $splBlock = BasicBlockHelper::append($context, 'cast_array_spl_hit');
        $govBlock = BasicBlockHelper::append($context, 'cast_array_gov_fallback');
        $mergeBlock = BasicBlockHelper::append($context, 'cast_array_spl_merge');
        $doneBlock = BasicBlockHelper::append($context, 'cast_array_spl_done');

        $context->builder->branchIf($isArray, $splBlock, $govBlock);

        $context->builder->positionAtEnd($splBlock);
        $fromSpl = HashTableHelper::emptyVariable($context);
        $fromSpl->value = $splBoxed;
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($govBlock);
        $fromGov = self::emitGetObjectVarsArray($context, $src, $mangledKeys);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);
        $phi = $context->builder->phi($fromSpl->value->typeOf());
        $phi->addIncoming($fromSpl->value, $splBlock);
        $phi->addIncoming($fromGov->value, $govBlock);
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($doneBlock);
        $result = HashTableHelper::emptyVariable($context);
        $result->value = $phi;

        return $result;
    }

    private static function operandToValueBox(Context $context, Variable $src): Value
    {
        if (Variable::TYPE_VALUE === $src->type) {
            return JitValueBox::valuePtrFromVariable($context, $src);
        }
        if (Variable::TYPE_OBJECT === $src->type) {
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            $context->builder->call(
                $context->lookupFunction('__value__writeObject'),
                $ptr,
                $context->helper->loadValue($src)
            );

            return $ptr;
        }

        throw new \LogicException(
            'object (array) cast requires object or boxed value operand: '.Variable::getStringType($src->type)
        );
    }

    private static function closureClassIdIfRegistered(Context $context): ?int
    {
        $object = $context->type->object;
        if (!$object instanceof ObjectBuiltin) {
            return null;
        }

        return $object->lookup('closure');
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
        // JitGetObjectVars returns a boxed `__value__*` (array), not a bare `__hashtable__*`
        // (#27020 — PHI type mismatch vs wrapResourceInArray / SPL cast).
        $boxed = JitGetObjectVars::invoke($context, $src, $mangledKeys);
        $ht = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $boxed
        );

        return new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            $ht
        );
    }
}

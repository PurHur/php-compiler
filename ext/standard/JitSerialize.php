<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringSerialize;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\ReflectionBuiltinHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ArrayObjectJitHelper;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT serialize() lowering via SerializeNestedJitHelper PHP (#6852, #20773, #27030; ensureLinked #33207).
 *
 * Type no longer always-declares `__compiler_serialize_*` after the leftover always-on shell
 * drop (#33207) — must {@see StringSerialize::ensureLinked} before lookup.
 *
 * Boxed `__value__*` arrays must use the hashtable ABI; objects use class name +
 * get_object_vars (type-tag, not null HT pointer — peer JitJsonEncode #27020).
 */
final class JitSerialize
{
    private static int $blockSerial = 0;

    public static function encode(Context $context, JITVariable $arg): Value
    {
        StringSerialize::ensureLinked($context);
        $flags = $context->getTypeFromString('int64')->constInt(0, false);

        if (JITVariable::TYPE_HASHTABLE === $arg->type || ArrayBuiltinHelper::isNativeArray($arg->type)) {
            $ht = JITVariable::TYPE_HASHTABLE === $arg->type
                ? $context->helper->loadValue($arg)
                : ArrayBuiltinHelper::loadHashTable($context, $arg);

            return $context->builder->call(
                $context->lookupFunction('__compiler_serialize_hashtable'),
                $ht,
                $flags
            );
        }
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return self::encodeObjectOperand($context, $arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return self::encodeBoxedValue($context, JitValueBox::valuePtrFromVariable($context, $arg), $flags);
        }

        return $context->builder->call(
            $context->lookupFunction('__compiler_serialize_value'),
            JitValueBox::valuePtrFromVariable($context, $arg),
            $flags
        );
    }

    private static function encodeBoxedValue(Context $context, Value $valuePtr, Value $flags): Value
    {
        $valuePtr = JitValueBox::normalizeValuePtr($context, $valuePtr);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $isHt = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(JITVariable::TYPE_HASHTABLE & 0x7f, false)
        );
        $isObj = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(JITVariable::TYPE_OBJECT & 0x7f, false)
        );

        $id = (string) (++self::$blockSerial);
        $htBlock = BasicBlockHelper::append($context, 'serialize_boxed_ht_'.$id);
        $objBlock = BasicBlockHelper::append($context, 'serialize_boxed_obj_'.$id);
        $valueBlock = BasicBlockHelper::append($context, 'serialize_boxed_value_'.$id);
        $notHt = BasicBlockHelper::append($context, 'serialize_boxed_not_ht_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'serialize_boxed_done_'.$id);
        $context->builder->branchIf($isHt, $htBlock, $notHt);

        $context->builder->positionAtEnd($htBlock);
        $ht = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $valuePtr
        );
        $htResult = $context->builder->call(
            $context->lookupFunction('__compiler_serialize_hashtable'),
            $ht,
            $flags
        );
        $htEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($notHt);
        $context->builder->branchIf($isObj, $objBlock, $valueBlock);

        $context->builder->positionAtEnd($objBlock);
        $objResult = self::encodeBoxedObject($context, $valuePtr);
        $objEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($valueBlock);
        $valueResult = $context->builder->call(
            $context->lookupFunction('__compiler_serialize_value'),
            $valuePtr,
            $flags
        );
        $valueEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $strPtr = $context->getTypeFromString('__string__*');
        $phi = $context->builder->phi($strPtr, 'serialize_boxed_phi_'.$id);
        $phi->addIncoming($htResult, $htEnd);
        $phi->addIncoming($objResult, $objEnd);
        $phi->addIncoming($valueResult, $valueEnd);

        return $phi;
    }

    private static function encodeObjectOperand(Context $context, JITVariable $arg): Value
    {
        $splResult = self::tryEncodeSplArrayObject($context, $arg);
        if (null !== $splResult) {
            return $splResult;
        }
        $className = ReflectionBuiltinHelper::getClassName($context, $arg);
        $varsBoxed = JitGetObjectVars::invoke($context, $arg, false);
        $ht = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            JitValueBox::normalizeValuePtr($context, $varsBoxed)
        );

        return $context->builder->call(
            $context->lookupFunction('__compiler_serialize_object'),
            $className,
            $ht
        );
    }

    /**
     * ArrayObject / ArrayIterator / RecursiveArrayIterator — Zend __serialize bag from `__spl_ht` (#33625).
     */
    private static function tryEncodeSplArrayObject(Context $context, JITVariable $arg): ?Value
    {
        $objectType = $context->type->object;
        $classIds = [];
        foreach (['ArrayObject', 'ArrayIterator', 'RecursiveArrayIterator'] as $name) {
            $id = $objectType->classIdByName($name)
                ?? $objectType->classIdForLowerName(strtolower($name));
            if (null !== $id) {
                $classIds[] = $id;
            }
        }
        if ([] === $classIds) {
            return null;
        }

        $obj = ArrayObjectJitHelper::loadObjectPtr($context, $arg);
        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($obj, $objMap['class_id'])
        );

        $id = (string) (++self::$blockSerial);
        $splBlock = BasicBlockHelper::append($context, 'serialize_spl_array_'.$id);
        $pubBlock = BasicBlockHelper::append($context, 'serialize_pub_props_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'serialize_spl_done_'.$id);

        $isSpl = $context->builder->icmp(
            Builder::INT_EQ,
            $classId,
            $context->constantFromInteger($classIds[0], 'int64')
        );
        for ($i = 1, $n = \count($classIds); $i < $n; ++$i) {
            $isSpl = $context->builder->or(
                $isSpl,
                $context->builder->icmp(
                    Builder::INT_EQ,
                    $classId,
                    $context->constantFromInteger($classIds[$i], 'int64')
                )
            );
        }
        $context->builder->branchIf($isSpl, $splBlock, $pubBlock);

        $context->builder->positionAtEnd($splBlock);
        $objVar = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $obj);
        $splResult = ArrayObjectJitHelper::compileSerialize($context, $objVar);
        $splEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($pubBlock);
        $className = ReflectionBuiltinHelper::getClassName($context, $arg);
        $varsBoxed = JitGetObjectVars::invoke($context, $arg, false);
        $ht = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            JitValueBox::normalizeValuePtr($context, $varsBoxed)
        );
        $pubResult = $context->builder->call(
            $context->lookupFunction('__compiler_serialize_object'),
            $className,
            $ht
        );
        $pubEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $strPtr = $context->getTypeFromString('__string__*');
        $phi = $context->builder->phi($strPtr, 'serialize_obj_phi_'.$id);
        $phi->addIncoming($splResult, $splEnd);
        $phi->addIncoming($pubResult, $pubEnd);

        return $phi;
    }

    private static function encodeBoxedObject(Context $context, Value $valuePtr): Value
    {
        $objVar = new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VALUE, $valuePtr);

        return self::encodeObjectOperand($context, $objVar);
    }
}

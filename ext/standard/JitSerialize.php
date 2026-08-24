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

    /** Public for {@see \PHPCompiler\JIT\SerializeArrayLlvm} value walk (#34483 / peer JitJsonEncode). */
    public static function encodeBoxedValue(Context $context, Value $valuePtr, Value $flags): Value
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
        $splResult = self::tryEncodeSplHtObject($context, $arg);
        if (null !== $splResult) {
            return $splResult;
        }

        return self::encodePublicObjectProps($context, $arg);
    }

    /**
     * Public/dynamic props via get_object_vars + NestedJIT object wire (#27030 / #33692).
     */
    private static function encodePublicObjectProps(Context $context, JITVariable $objectOperand): Value
    {
        $className = ReflectionBuiltinHelper::getClassName($context, $objectOperand);
        $varsBoxed = JitGetObjectVars::invoke($context, $objectOperand, false);
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
     * SPL classes that store in `__spl_ht` — ArrayObject bag (#33625) / SplFixedArray
     * elements (#33634) / SplObjectStorage object-key pairs (#33876) / SplDoublyLinkedList
     * flags+dllist bag (#33966).
     *
     * Only emit IR / phi incomings for registered SPL ids. Unreachable
     * SplFixedArray/ArrayObject/SOS/Dllist compileSerialize blocks in the phi poisoned
     * stdClass serialize (SIGSEGV, #33959) while user classes still worked.
     */
    private static function tryEncodeSplHtObject(Context $context, JITVariable $arg): ?Value
    {
        $objectType = $context->type->object;
        $sosId = $objectType->classIdByName('SplObjectStorage')
            ?? $objectType->classIdForLowerName('splobjectstorage');
        $fixedId = $objectType->classIdByName('SplFixedArray')
            ?? $objectType->classIdForLowerName('splfixedarray');
        $dllIds = [];
        foreach (['SplDoublyLinkedList', 'SplQueue', 'SplStack'] as $name) {
            $id = $objectType->classIdByName($name)
                ?? $objectType->classIdForLowerName(strtolower($name));
            if (null !== $id) {
                $dllIds[] = $id;
            }
        }
        $arrayIds = [];
        foreach (['ArrayObject', 'ArrayIterator', 'RecursiveArrayIterator'] as $name) {
            $id = $objectType->classIdByName($name)
                ?? $objectType->classIdForLowerName(strtolower($name));
            if (null !== $id) {
                $arrayIds[] = $id;
            }
        }
        if (null === $sosId && null === $fixedId && [] === $dllIds && [] === $arrayIds) {
            return null;
        }

        $obj = ArrayObjectJitHelper::loadObjectPtr($context, $arg);
        $objVar = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $obj);
        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($obj, $objMap['class_id'])
        );

        $id = (string) (++self::$blockSerial);
        $doneBlock = BasicBlockHelper::append($context, 'serialize_spl_done_'.$id);
        $pubBlock = BasicBlockHelper::append($context, 'serialize_spl_pub_'.$id);
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        /** @var list<array{0: Value, 1: mixed}> $phiIncoming */
        $phiIncoming = [];

        $continue = $context->builder->getInsertBlock();

        if (null !== $sosId) {
            $sosBlock = BasicBlockHelper::append($context, 'serialize_spl_sos_'.$id);
            $notSos = BasicBlockHelper::append($context, 'serialize_spl_not_sos_'.$id);
            $context->builder->positionAtEnd($continue);
            $isSos = $context->builder->icmp(
                Builder::INT_EQ,
                $classId,
                $i64->constInt($sosId, false)
            );
            $context->builder->branchIf($isSos, $sosBlock, $notSos);
            $context->builder->positionAtEnd($sosBlock);
            $sosResult = \PHPCompiler\VM\SplObjectStorageJitHelper::compileSerialize($context, $objVar);
            $sosEnd = $context->builder->getInsertBlock();
            $context->builder->branch($doneBlock);
            $phiIncoming[] = [$sosResult, $sosEnd];
            $continue = $notSos;
        }

        if (null !== $fixedId) {
            $fixedBlock = BasicBlockHelper::append($context, 'serialize_spl_fixed_'.$id);
            $notFixed = BasicBlockHelper::append($context, 'serialize_spl_not_fixed_'.$id);
            $context->builder->positionAtEnd($continue);
            $isFixed = $context->builder->icmp(
                Builder::INT_EQ,
                $classId,
                $i64->constInt($fixedId, false)
            );
            $context->builder->branchIf($isFixed, $fixedBlock, $notFixed);
            $context->builder->positionAtEnd($fixedBlock);
            $fixedResult = \PHPCompiler\VM\SplFixedArrayJitHelper::compileSerialize($context, $objVar);
            $fixedEnd = $context->builder->getInsertBlock();
            $context->builder->branch($doneBlock);
            $phiIncoming[] = [$fixedResult, $fixedEnd];
            $continue = $notFixed;
        }

        if ([] !== $dllIds) {
            $dllBlock = BasicBlockHelper::append($context, 'serialize_spl_dll_'.$id);
            $notDll = BasicBlockHelper::append($context, 'serialize_spl_not_dll_'.$id);
            $context->builder->positionAtEnd($continue);
            $isDll = $context->builder->icmp(
                Builder::INT_EQ,
                $classId,
                $i64->constInt($dllIds[0], false)
            );
            for ($i = 1, $n = \count($dllIds); $i < $n; ++$i) {
                $isDll = $context->builder->or(
                    $isDll,
                    $context->builder->icmp(
                        Builder::INT_EQ,
                        $classId,
                        $i64->constInt($dllIds[$i], false)
                    )
                );
            }
            $context->builder->branchIf($isDll, $dllBlock, $notDll);
            $context->builder->positionAtEnd($dllBlock);
            $dllResult = \PHPCompiler\VM\SplDllistJitHelper::compileSerialize($context, $objVar);
            $dllEnd = $context->builder->getInsertBlock();
            $context->builder->branch($doneBlock);
            $phiIncoming[] = [$dllResult, $dllEnd];
            $continue = $notDll;
        }

        if ([] !== $arrayIds) {
            $arrayBlock = BasicBlockHelper::append($context, 'serialize_spl_array_'.$id);
            $context->builder->positionAtEnd($continue);
            $isArray = $context->builder->icmp(
                Builder::INT_EQ,
                $classId,
                $i64->constInt($arrayIds[0], false)
            );
            for ($i = 1, $n = \count($arrayIds); $i < $n; ++$i) {
                $isArray = $context->builder->or(
                    $isArray,
                    $context->builder->icmp(
                        Builder::INT_EQ,
                        $classId,
                        $i64->constInt($arrayIds[$i], false)
                    )
                );
            }
            $context->builder->branchIf($isArray, $arrayBlock, $pubBlock);
            $context->builder->positionAtEnd($arrayBlock);
            $arrayResult = ArrayObjectJitHelper::compileSerialize($context, $objVar);
            $arrayEnd = $context->builder->getInsertBlock();
            $context->builder->branch($doneBlock);
            $phiIncoming[] = [$arrayResult, $arrayEnd];
        } else {
            $context->builder->positionAtEnd($continue);
            $context->builder->branch($pubBlock);
        }

        $context->builder->positionAtEnd($pubBlock);
        // Loaded object ptr — same shape as SPL arms (avoids re-load of $arg).
        $pubResult = self::encodePublicObjectProps($context, $objVar);
        $pubEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);
        $phiIncoming[] = [$pubResult, $pubEnd];

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($strPtr, 'serialize_spl_ht_phi_'.$id);
        foreach ($phiIncoming as [$val, $pred]) {
            $phi->addIncoming($val, $pred);
        }

        return $phi;
    }

    private static function encodeBoxedObject(Context $context, Value $valuePtr): Value
    {
        $objVar = new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VALUE, $valuePtr);

        return self::encodeObjectOperand($context, $objVar);
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringTriggerErrorJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\HashTableReadLlvm;
use PHPCompiler\JIT\HashTableWriteLlvm;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT ArrayObject — `__spl_ht` storage (#26823, #27286, #27567, ext/spl/spl_array.c).
 */
final class ArrayObjectJitHelper
{
    public const PROP_HT = '__spl_ht';

    /** php-src ArrayObject::$flags — SPL_ARRAY_* bitfield (#33061, spl_array.c). */
    public const PROP_FLAGS = '__flags';

    /** php-src ArrayObject iteratorClass string (#27567). */
    public const PROP_ITERATOR_CLASS = '__iterator_class';

    /** Compile-time resolved class id for getIterator() allocation (#27567). */
    public const PROP_ITERATOR_CLASS_ID = '__iterator_class_id';

    public const CLASS_NAME = 'ArrayObject';

    /** SPL_ARRAY_AS_PROPS — backing keys as object properties (ext/spl/spl_array.c). */
    public const FLAG_ARRAY_AS_PROPS = 2;

    /**
     * ArrayObject / ArrayIterator / RecursiveArrayIterator — ARRAY_AS_PROPS property handlers.
     */
    public static function isArrayAsPropsClass(string $classLc): bool
    {
        $lc = strtolower(ltrim($classLc, '\\'));

        return 'arrayobject' === $lc
            || 'arrayiterator' === $lc
            || 'recursivearrayiterator' === $lc;
    }

    /**
     * Classes whose `$obj[] =` must append into object `__spl_ht`, not overwrite the
     * value-box as a bare hashtable (#27286 — reserveAppendSlot on the object clobbers it).
     */
    public static function supportsEmptyDimAppend(?string $containerUserType): bool
    {
        if (null === $containerUserType || '' === $containerUserType) {
            return false;
        }
        $ut = strtolower(ltrim($containerUserType, '\\'));

        return 'arrayobject' === $ut
            || 'arrayiterator' === $ut
            || 'recursivearrayiterator' === $ut;
    }

    /**
     * Resolve `__spl_ht` for empty-dim append (`$o[] =`) from TYPE_OBJECT or boxed TYPE_VALUE.
     */
    public static function backingHashtableForAppend(Context $context, JITVariable $receiver): JITVariable
    {
        $obj = self::loadObject($context, $receiver);

        return $context->type->object->splBackingHashtable(
            new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $obj)
        );
    }

    /**
     * ARRAY_AS_PROPS property read — `$ao->key` reads `__spl_ht` like `$ao['key']` (#33061).
     * Returns null when the class/name is not handled here (caller uses declared slots).
     */
    public static function tryPropertyFetchRead(
        \PHPCompiler\JIT\Builtin\Type\Object_ $objectType,
        Value $obj,
        string $class,
        string $name
    ): ?JITVariable {
        if (!self::isArrayAsPropsClass($class) || str_starts_with($name, '__')) {
            return null;
        }
        $context = $objectType->jitContext();
        $classLc = strtolower(ltrim($class, '\\'));
        $className = match ($classLc) {
            'arrayobject' => 'ArrayObject',
            'arrayiterator' => 'ArrayIterator',
            'recursivearrayiterator' => 'RecursiveArrayIterator',
            default => $class,
        };

        $flagsSlot = $objectType->propertyFetch($obj, $className, self::PROP_FLAGS);
        $flagsVal = JITVariable::TYPE_NATIVE_LONG === $flagsSlot->type
            ? $context->helper->loadValue($flagsSlot)
            : $context->builder->call(
                $context->lookupFunction('__value__toLong'),
                JitValueBox::valuePtrFromVariable($context, $flagsSlot)
            );
        $i64 = $context->getTypeFromString('int64');
        $hasAsProps = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->and($flagsVal, $i64->constInt(self::FLAG_ARRAY_AS_PROPS, false)),
            $i64->constInt(0, false)
        );

        $resultSlot = JitValueBox::alloc($context);
        $destPtr = JitValueBox::pointer($context, $resultSlot);
        $fn = $context->builder->getInsertBlock()->getParent();
        // Sanitize block names — property names can be arbitrary identifiers.
        $suffix = substr(sha1($className.'::'.$name), 0, 8);
        $asPropsBb = $fn->appendBasicBlock('ao_as_props_'.$suffix);
        $noPropsBb = $fn->appendBasicBlock('ao_no_as_props_'.$suffix);
        $mergeBb = $fn->appendBasicBlock('ao_as_props_merge_'.$suffix);
        $context->builder->branchIf($hasAsProps, $asPropsBb, $noPropsBb);

        $context->builder->positionAtEnd($asPropsBb);
        $receiver = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $obj);
        $keyStr = $context->builder->load($context->constantStringFromString($name));
        $key = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $keyStr);
        $box = self::compileOffsetGet($context, $receiver, $key);
        JitValueBox::copyFromPointer(
            $context,
            $destPtr,
            JitValueBox::pointer($context, $box)
        );
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($noPropsBb);
        // Without ARRAY_AS_PROPS do not defineProperty (OOB slot → SIGSEGV). Quiet null (#33061).
        $context->builder->call($context->lookupFunction('__value__writeNull'), $destPtr);
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($mergeBb);

        return new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VARIABLE, $resultSlot);
    }

    /**
     * ARRAY_AS_PROPS property write lvalue — assignOperand calls {@see compilePropertyAssign} (#33068).
     */
    public static function tryPropertyFetchWrite(
        \PHPCompiler\JIT\Builtin\Type\Object_ $objectType,
        Value $obj,
        string $class,
        string $name
    ): ?JITVariable {
        if (!self::isArrayAsPropsClass($class) || str_starts_with($name, '__')) {
            return null;
        }
        $context = $objectType->jitContext();
        $classLc = strtolower(ltrim($class, '\\'));
        $className = match ($classLc) {
            'arrayobject' => 'ArrayObject',
            'arrayiterator' => 'ArrayIterator',
            'recursivearrayiterator' => 'RecursiveArrayIterator',
            default => $class,
        };
        $classId = $objectType->lookup($className);
        if ($objectType->hasProperty($classId, $name)) {
            return null;
        }
        // Placeholder null box — real store is compilePropertyAssign → offsetSet.
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );
        $var = new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VARIABLE, $slot);
        $var->arrayAsPropsReceiver = $obj;
        $var->arrayAsPropsName = $name;
        $var->arrayAsPropsClassName = $className;

        return $var;
    }

    /**
     * php-src spl_array_write_property — ARRAY_AS_PROPS → dimension write (#33068).
     */
    public static function compilePropertyAssign(
        Context $context,
        JITVariable $lvalue,
        JITVariable $value
    ): void {
        $obj = $lvalue->arrayAsPropsReceiver;
        $name = $lvalue->arrayAsPropsName;
        $className = $lvalue->arrayAsPropsClassName ?? 'ArrayObject';
        if (null === $obj || null === $name) {
            throw new \LogicException('arrayAsProps assign missing receiver/name');
        }
        $objectType = $context->type->object;
        $flagsSlot = $objectType->propertyFetch($obj, $className, self::PROP_FLAGS);
        $flagsVal = JITVariable::TYPE_NATIVE_LONG === $flagsSlot->type
            ? $context->helper->loadValue($flagsSlot)
            : $context->builder->call(
                $context->lookupFunction('__value__toLong'),
                JitValueBox::valuePtrFromVariable($context, $flagsSlot)
            );
        $i64 = $context->getTypeFromString('int64');
        $hasAsProps = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->and($flagsVal, $i64->constInt(self::FLAG_ARRAY_AS_PROPS, false)),
            $i64->constInt(0, false)
        );
        $fn = $context->builder->getInsertBlock()->getParent();
        $suffix = substr(sha1($className.'::'.$name.'=write'), 0, 8);
        $asPropsBb = $fn->appendBasicBlock('ao_as_props_w_'.$suffix);
        $doneBb = $fn->appendBasicBlock('ao_as_props_w_done_'.$suffix);
        // Without ARRAY_AS_PROPS: do not defineProperty (OOB abort). Quiet no-op for thin AOT.
        $context->builder->branchIf($hasAsProps, $asPropsBb, $doneBb);

        $context->builder->positionAtEnd($asPropsBb);
        $receiver = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $obj);
        $keyStr = $context->builder->load($context->constantStringFromString($name));
        $key = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $keyStr);
        self::compileOffsetSet($context, $receiver, $key, $value);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
    }

    /**
     * php-src spl_array_has_property — ARRAY_AS_PROPS backing key for property_exists/isset (#33068).
     *
     * @return Value i1
     */
    public static function compilePropertyExists(
        Context $context,
        Value $obj,
        string $className,
        string $propName
    ): Value {
        $objectType = $context->type->object;
        $flagsSlot = $objectType->propertyFetch($obj, $className, self::PROP_FLAGS);
        $flagsVal = JITVariable::TYPE_NATIVE_LONG === $flagsSlot->type
            ? $context->helper->loadValue($flagsSlot)
            : $context->builder->call(
                $context->lookupFunction('__value__toLong'),
                JitValueBox::valuePtrFromVariable($context, $flagsSlot)
            );
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $hasAsProps = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->and($flagsVal, $i64->constInt(self::FLAG_ARRAY_AS_PROPS, false)),
            $i64->constInt(0, false)
        );
        $fn = $context->builder->getInsertBlock()->getParent();
        $suffix = substr(sha1($className.'::'.$propName.'?'), 0, 8);
        $asPropsBb = $fn->appendBasicBlock('ao_pex_as_'.$suffix);
        $noBb = $fn->appendBasicBlock('ao_pex_no_'.$suffix);
        $mergeBb = $fn->appendBasicBlock('ao_pex_merge_'.$suffix);
        $context->builder->branchIf($hasAsProps, $asPropsBb, $noBb);

        $context->builder->positionAtEnd($asPropsBb);
        $ht = self::htPtr($context, $obj);
        $keyStr = $context->builder->load($context->constantStringFromString($propName));
        $key = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $keyStr);
        $isSet = HashTableHelper::offsetIsSetDim($context, $ht, self::asValueBoxKey($context, $key));
        // offsetIsSetDim emits its own BBs — phi must cite the block that defines $isSet.
        $asPropsEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($noBb);
        $false = $i1->constInt(0, false);
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($mergeBb);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($isSet, $asPropsEnd);
        $phi->addIncoming($false, $noBb);

        return $phi;
    }

    public static function compileCount(Context $context, JITVariable $receiver): Value
    {
        $ht = self::htPtr($context, self::loadObject($context, $receiver));
        $map = $context->structFieldMap['__hashtable__'];
        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong(
            $context,
            $slot,
            $context->builder->truncOrBitCast($n, $context->getTypeFromString('int64'))
        );

        return $slot;
    }

    /** php-src spl_array_method_append — next numeric index into storage. */
    public static function compileAppend(Context $context, JITVariable $receiver, JITVariable $value): Value
    {
        $ht = self::htPtr($context, self::loadObject($context, $receiver));
        $htVar = new JITVariable($context, JITVariable::TYPE_HASHTABLE, JITVariable::KIND_VALUE, $ht);
        HashTableHelper::addElement($context, $htVar, $value, null);

        return self::voidResult($context);
    }

    public static function compileGetArrayCopy(Context $context, JITVariable $receiver): Value
    {
        $ht = self::htPtr($context, self::loadObject($context, $receiver));
        $copy = new JITVariable(
            $context,
            JITVariable::TYPE_HASHTABLE,
            JITVariable::KIND_VALUE,
            HashTableHelper::alloc($context)
        );
        HashTableHelper::spreadInto(
            $context,
            $copy,
            new JITVariable($context, JITVariable::TYPE_HASHTABLE, JITVariable::KIND_VALUE, $ht)
        );
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            JitValueBox::pointer($context, $slot),
            $context->helper->loadValue($copy)
        );

        return $slot;
    }

    /**
     * php-src spl_array_object_exchange_array — replace `__spl_ht`, return previous (#33083).
     *
     * Thin AOT: array|ArrayObject/ArrayIterator input → owned packed copy into the
     * receiver slot (php-src just_array / Z_PARAM_ARRAY_OR_OBJECT). Shared USE_OTHER
     * iterator retarget is VM-only ({@see SplArrayStorage::exchangeArray}).
     */
    public static function compileExchangeArray(
        Context $context,
        JITVariable $receiver,
        JITVariable $input
    ): Value {
        $obj = self::loadObject($context, $receiver);
        $objectType = $context->type->object;
        $oldHt = self::htPtr($context, $obj);
        $oldCopy = new JITVariable(
            $context,
            JITVariable::TYPE_HASHTABLE,
            JITVariable::KIND_VALUE,
            HashTableHelper::alloc($context)
        );
        HashTableHelper::spreadInto(
            $context,
            $oldCopy,
            new JITVariable($context, JITVariable::TYPE_HASHTABLE, JITVariable::KIND_VALUE, $oldHt)
        );

        $src = HashTableHelper::coerceToPackedHashtable($context, $input);
        $fresh = new JITVariable(
            $context,
            JITVariable::TYPE_HASHTABLE,
            JITVariable::KIND_VALUE,
            HashTableHelper::alloc($context)
        );
        HashTableHelper::spreadInto($context, $fresh, $src);
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, self::CLASS_NAME, self::PROP_HT),
            $fresh,
            JITVariable::TYPE_HASHTABLE
        );

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            JitValueBox::pointer($context, $slot),
            $context->helper->loadValue($oldCopy)
        );

        return $slot;
    }

    public static function compileOffsetGet(Context $context, JITVariable $receiver, JITVariable $key): Value
    {
        $ht = self::htPtr($context, self::loadObject($context, $receiver));
        $boxedKey = self::asValueBoxKey($context, $key);
        // php-src spl_array_read_dimension — Undefined array key then null (#28820).
        // Int-key HT reads do not warn; string-key reads do — gate both on offsetIsSet.
        $isSet = HashTableHelper::offsetIsSetDim($context, $ht, $boxedKey);
        $slot = JitValueBox::alloc($context);
        $destPtr = JitValueBox::pointer($context, $slot);
        $fn = $context->builder->getInsertBlock()->getParent();
        $hitBb = $fn->appendBasicBlock('ao_offsetget_hit');
        $missBb = $fn->appendBasicBlock('ao_offsetget_miss');
        $doneBb = $fn->appendBasicBlock('ao_offsetget_done');
        $context->builder->branchIf($isSet, $hitBb, $missBb);

        $context->builder->positionAtEnd($missBb);
        self::emitUndefinedArrayKeyWarning($context, $boxedKey);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $destPtr);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($hitBb);
        // Key present — readValueBoxKeyToValueBox will not emit a duplicate string-key warning.
        $box = HashTableReadLlvm::readValueBoxKeyToValueBox($context, $ht, $boxedKey, null);
        JitValueBox::copyFromPointer(
            $context,
            $destPtr,
            JitValueBox::pointer($context, $box->value)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $slot;
    }

    /**
     * Emit Zend "Undefined array key …" for a boxed dim (#28820).
     */
    private static function emitUndefinedArrayKeyWarning(Context $context, JITVariable $boxedKey): void
    {
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        StringTriggerErrorJit::implement($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'ao_undef_key_setup');
        }

        $valPtr = HashTableReadLlvm::valuePtrFromDim($context, $boxedKey);
        $valueMap = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $typeByte = $context->builder->load(
            $context->builder->structGep($valPtr, $valueMap['type'])
        );
        $fn = $context->builder->getInsertBlock()->getParent();
        $strBb = $fn->appendBasicBlock('ao_undef_key_str');
        $longBb = $fn->appendBasicBlock('ao_undef_key_long');
        $doneBb = $fn->appendBasicBlock('ao_undef_key_done');
        $afterStr = $fn->appendBasicBlock('ao_undef_key_after_str');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(JITVariable::TYPE_STRING, false)
            ),
            $strBb,
            $afterStr
        );
        $context->builder->positionAtEnd($strBb);
        $keyStr = $context->builder->call($context->lookupFunction('__value__readString'), $valPtr);
        $strMap = $context->structFieldMap['__string__'];
        $keyLen = $context->builder->load($context->builder->structGep($keyStr, $strMap['length']));
        $keyBytes = $context->builder->structGep($keyStr, $strMap['value']);
        $i8p = $context->getTypeFromString('int8*');
        $keyCStr = $context->builder->pointerCast($keyBytes, $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_undefined_array_key_warning_cstr'),
            $keyCStr,
            $keyLen
        );
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($afterStr);
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(JITVariable::TYPE_NATIVE_LONG, false)
            ),
            $longBb,
            $doneBb
        );
        $context->builder->positionAtEnd($longBb);
        $longKey = $context->builder->call($context->lookupFunction('__value__readLong'), $valPtr);
        $context->builder->call(
            $context->lookupFunction('__compiler_undefined_array_key_warning_long'),
            $longKey
        );
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
    }

    public static function compileOffsetSet(
        Context $context,
        JITVariable $receiver,
        JITVariable $key,
        JITVariable $value
    ): Value {
        $ht = self::htPtr($context, self::loadObject($context, $receiver));
        $htVar = new JITVariable($context, JITVariable::TYPE_HASHTABLE, JITVariable::KIND_VALUE, $ht);
        // php-src: null offset → append (zend_hash_next_index_insert); do not coerce to '' (#27286).
        if (JITVariable::TYPE_NULL === $key->type || ($key->isNullConstant ?? false)) {
            HashTableHelper::addElement($context, $htVar, $value, null);

            return self::voidResult($context);
        }
        if (JITVariable::TYPE_VALUE === $key->type || JitValueBox::isValueOperand($key)) {
            $valPtr = \PHPCompiler\JIT\HashTableReadLlvm::valuePtrFromDim($context, $key);
            $valueMap = $context->structFieldMap['__value__'];
            $i8 = $context->getTypeFromString('int8');
            $typeByte = $context->builder->load(
                $context->builder->structGep($valPtr, $valueMap['type'])
            );
            $fn = $context->builder->getInsertBlock()->getParent();
            $nullBb = $fn->appendBasicBlock('ao_offsetset_null_append');
            $keyedBb = $fn->appendBasicBlock('ao_offsetset_keyed');
            $doneBb = $fn->appendBasicBlock('ao_offsetset_done');
            $context->builder->branchIf(
                $context->builder->icmp(
                    \PHPLLVM\Builder::INT_EQ,
                    $typeByte,
                    $i8->constInt(JITVariable::TYPE_NULL, false)
                ),
                $nullBb,
                $keyedBb
            );
            $context->builder->positionAtEnd($nullBb);
            HashTableHelper::addElement($context, $htVar, $value, null);
            $context->builder->branch($doneBb);
            $context->builder->positionAtEnd($keyedBb);
            HashTableHelper::setValueBoxKey($context, $ht, $key, $value);
            $context->builder->branch($doneBb);
            $context->builder->positionAtEnd($doneBb);

            return self::voidResult($context);
        }
        HashTableHelper::setValueBoxKey($context, $ht, self::asValueBoxKey($context, $key), $value);

        return self::voidResult($context);
    }

    public static function compileOffsetExists(Context $context, JITVariable $receiver, JITVariable $key): Value
    {
        $ht = self::htPtr($context, self::loadObject($context, $receiver));
        $isSet = HashTableHelper::offsetIsSetDim($context, $ht, self::asValueBoxKey($context, $key));
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $isSet);

        return $slot;
    }

    public static function compileOffsetUnset(Context $context, JITVariable $receiver, JITVariable $key): Value
    {
        $ht = self::htPtr($context, self::loadObject($context, $receiver));
        HashTableWriteLlvm::unsetValueBoxKey($context, $ht, self::asValueBoxKey($context, $key));

        return self::voidResult($context);
    }

    /** php-src SPL_METHOD(ArrayObject, getIteratorClass) (#27567). */
    public static function compileGetIteratorClass(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $nameSlot = $context->type->object->propertyFetch($obj, self::CLASS_NAME, self::PROP_ITERATOR_CLASS);
        $namePtr = JITVariable::TYPE_STRING === $nameSlot->type
            ? $context->helper->loadValue($nameSlot)
            : $context->builder->call(
                $context->lookupFunction('__value__readString'),
                JitValueBox::valuePtrFromVariable($context, $nameSlot)
            );
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $namePtr
        );

        return $slot;
    }

    /**
     * php-src spl_array_get_iterator — allocate iteratorClass, copy `__spl_ht` (#27567).
     *
     * Thin AOT uses the class id stored at construct (from `MyIter::class` literal).
     */
    public static function compileGetIterator(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $objectType = $context->type->object;
        $idSlot = $objectType->propertyFetch($obj, self::CLASS_NAME, self::PROP_ITERATOR_CLASS_ID);
        $classId = JITVariable::TYPE_NATIVE_LONG === $idSlot->type
            ? $context->helper->loadValue($idSlot)
            : $context->builder->call(
                $context->lookupFunction('__value__toLong'),
                JitValueBox::valuePtrFromVariable($context, $idSlot)
            );
        $iterObj = $objectType->allocateForRuntimeClassId($classId);
        $srcHt = self::htPtr($context, $obj);
        $copy = new JITVariable(
            $context,
            JITVariable::TYPE_HASHTABLE,
            JITVariable::KIND_VALUE,
            HashTableHelper::alloc($context)
        );
        HashTableHelper::spreadInto(
            $context,
            $copy,
            new JITVariable($context, JITVariable::TYPE_HASHTABLE, JITVariable::KIND_VALUE, $srcHt)
        );
        // Slot 0 is `__spl_ht` for ArrayIterator family (#26783); subclasses inherit layout.
        $objectType->propertyStore(
            $objectType->propertySlotFor($iterObj, 'ArrayIterator', self::PROP_HT),
            $copy,
            JITVariable::TYPE_HASHTABLE
        );
        $objectType->markObjectConstructed($iterObj);
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $slot),
            $iterObj
        );

        return $slot;
    }

    /** Dim keys from literals are TYPE_STRING / NATIVE_LONG — box for HT value-key APIs. */
    private static function asValueBoxKey(Context $context, JITVariable $key): JITVariable
    {
        if (JITVariable::TYPE_VALUE === $key->type || JitValueBox::isValueOperand($key)) {
            return $key;
        }
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        switch ($key->type) {
            case JITVariable::TYPE_STRING:
                $context->builder->call(
                    $context->lookupFunction('__value__writeString'),
                    $ptr,
                    $context->helper->loadValue($key)
                );
                break;
            case JITVariable::TYPE_NATIVE_LONG:
                JitValueBox::writeLong($context, $slot, $context->helper->loadValue($key));
                break;
            case JITVariable::TYPE_NATIVE_BOOL:
                JitValueBox::writeBool($context, $slot, $context->helper->loadValue($key));
                break;
            case JITVariable::TYPE_NULL:
                $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);
                break;
            default:
                throw new \LogicException(
                    'ArrayObject offset key type '.JITVariable::getStringType($key->type).' unsupported in thin AOT'
                );
        }

        return new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VARIABLE, $slot);
    }

    private static function htPtr(Context $context, Value $obj): Value
    {
        return $context->helper->loadValue(
            $context->type->object->splBackingHashtable(
                new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $obj)
            )
        );
    }

    private static function loadObject(Context $context, JITVariable $receiver): Value
    {
        if (JITVariable::TYPE_OBJECT === $receiver->type) {
            return $context->helper->loadValue($receiver);
        }
        if (JITVariable::TYPE_VALUE === $receiver->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $receiver)
            );
        }

        throw new \LogicException('ArrayObject method requires an object receiver');
    }

    private static function voidResult(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }
}

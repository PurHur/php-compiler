<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\KeySortRuntime;
use PHPCompiler\JIT\Builtin\NaturalSortRuntime;
use PHPCompiler\JIT\Builtin\StringTriggerErrorJit;
use PHPCompiler\JIT\Builtin\UsortRuntime;
use PHPCompiler\JIT\Builtin\ValueSortRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\HashTableReadLlvm;
use PHPCompiler\JIT\HashTableWriteLlvm;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\StdlibConstants;
use PHPCompiler\ext\standard\VmInternalCompare;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT ArrayObject — `__spl_ht` storage (#26823, #27286, #27567, #33606, ext/spl/spl_array.c).
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

    /**
     * php-src spl_array_object_sort — in-place asort on `__spl_ht` (#33606 / #19480).
     *
     * Thin AOT reuses procedural asort LLVM ({@see ValueSortRuntime}); NestedJIT
     * ValueSortJitHelper aborts under standalone AOT (#27227).
     */
    public static function compileAsort(
        Context $context,
        JITVariable $receiver,
        ?JITVariable $flagsArg = null,
        string $function = 'ArrayObject::asort'
    ): Value {
        $htVar = self::backingHtVar($context, $receiver);
        if (null === $flagsArg) {
            ValueSortRuntime::asortByValue($context, $htVar);
        } else {
            self::asortByValueWithFlags(
                $context,
                $htVar,
                VmInternalCompare::resolveJitSortFlags($context, $flagsArg, $function)
            );
        }

        return self::trueResult($context);
    }

    /**
     * php-src zim_ArrayObject_ksort — in-place ksort on `__spl_ht` (#33606 / #19480).
     */
    public static function compileKsort(
        Context $context,
        JITVariable $receiver,
        ?JITVariable $flagsArg = null,
        string $function = 'ArrayObject::ksort'
    ): Value {
        $htVar = self::backingHtVar($context, $receiver);
        if (null === $flagsArg) {
            KeySortRuntime::ksortByKey($context, $htVar);
        } else {
            self::ksortByKeyWithFlags(
                $context,
                $htVar,
                VmInternalCompare::resolveJitSortFlags($context, $flagsArg, $function)
            );
        }

        return self::trueResult($context);
    }

    /**
     * php-src zim_ArrayObject_natsort — natural value sort on `__spl_ht` (#33606 / #19480).
     */
    public static function compileNatsort(Context $context, JITVariable $receiver): Value
    {
        NaturalSortRuntime::natsortByValue($context, self::backingHtVar($context, $receiver));

        return self::trueResult($context);
    }

    /**
     * php-src zim_ArrayObject_natcasesort — case-insensitive natural sort (#33606 / #19480).
     */
    public static function compileNatcasesort(Context $context, JITVariable $receiver): Value
    {
        NaturalSortRuntime::natcasesortByValue($context, self::backingHtVar($context, $receiver));

        return self::trueResult($context);
    }

    /**
     * php-src spl_array_object_uasort — in-place user value sort on `__spl_ht` (#33613 / #9356).
     *
     * Thin AOT reuses procedural uasort LLVM ({@see UsortRuntime}); NestedJIT keyed
     * helpers abort under standalone AOT (#27217).
     */
    public static function compileUasort(
        Context $context,
        JITVariable $receiver,
        JITVariable $callback
    ): Value {
        UsortRuntime::uasortValues($context, self::backingHtVar($context, $receiver), $callback);

        return self::trueResult($context);
    }

    /**
     * php-src spl_array_object_uksort — in-place user key sort on `__spl_ht` (#33613 / #9356).
     */
    public static function compileUksort(
        Context $context,
        JITVariable $receiver,
        JITVariable $callback
    ): Value {
        UsortRuntime::uksortKeys($context, self::backingHtVar($context, $receiver), $callback);

        return self::trueResult($context);
    }

    private static function backingHtVar(Context $context, JITVariable $receiver): JITVariable
    {
        $ht = self::htPtr($context, self::loadObject($context, $receiver));

        return new JITVariable($context, JITVariable::TYPE_HASHTABLE, JITVariable::KIND_VALUE, $ht);
    }

    /** Mirror {@see \PHPCompiler\ext\standard\asort_::jitSortByValueWithFlags}. */
    private static function asortByValueWithFlags(Context $context, JITVariable $array, int $flags): void
    {
        $sortType = $flags & ~StdlibConstants::SORT_FLAG_CASE;
        if (StdlibConstants::SORT_LOCALE_STRING === $sortType) {
            ValueSortRuntime::asortByValueLocale($context, $array);

            return;
        }
        if (
            StdlibConstants::SORT_REGULAR === $sortType
            || StdlibConstants::SORT_NUMERIC === $sortType
            || StdlibConstants::SORT_STRING === $sortType
        ) {
            ValueSortRuntime::asortByValue($context, $array);

            return;
        }
        if (StdlibConstants::SORT_NATURAL === $sortType) {
            if (0 !== ($flags & StdlibConstants::SORT_FLAG_CASE)) {
                NaturalSortRuntime::natcasesortByValue($context, $array);
            } else {
                NaturalSortRuntime::natsortByValue($context, $array);
            }

            return;
        }
        ValueSortRuntime::asortByValue($context, $array);
    }

    /** Mirror {@see \PHPCompiler\ext\standard\ksort_::jitSortByKeyWithFlags}. */
    private static function ksortByKeyWithFlags(Context $context, JITVariable $array, int $flags): void
    {
        $sortType = $flags & ~StdlibConstants::SORT_FLAG_CASE;
        if (StdlibConstants::SORT_LOCALE_STRING === $sortType) {
            KeySortRuntime::ksortByKeyLocale($context, $array);

            return;
        }
        if (
            StdlibConstants::SORT_REGULAR === $sortType
            || StdlibConstants::SORT_STRING === $sortType
        ) {
            KeySortRuntime::ksortByKey($context, $array);

            return;
        }
        if (StdlibConstants::SORT_NUMERIC === $sortType || StdlibConstants::SORT_NATURAL === $sortType) {
            throw new \LogicException(
                'ksort() flags are not supported in JIT/AOT in this compiler build'
            );
        }
        KeySortRuntime::ksortByKey($context, $array);
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

    /**
     * php-src ArrayObject/ArrayIterator::__serialize bag under thin AOT (#33625).
     *
     * Prefer helper-runtime (avoid PHP_COMPILER_HELPER_RUNTIME_O=0) — peer #32925.
     *
     * @return Value {@see __string__*} full `O:len:"Class":4:{…}` wire
     */
    public static function compileSerialize(Context $context, JITVariable $receiver): Value
    {
        \PHPCompiler\JIT\Builtin\StringSerialize::ensureLinked($context);
        $obj = self::loadObject($context, $receiver);
        $objVar = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $obj);
        $classNameStr = \PHPCompiler\JIT\ReflectionBuiltinHelper::getClassName($context, $objVar);

        $flagsSlot = $context->type->object->propertyFetch($obj, self::CLASS_NAME, self::PROP_FLAGS);
        $flags = JITVariable::TYPE_NATIVE_LONG === $flagsSlot->type
            ? $context->helper->loadValue($flagsSlot)
            : $context->builder->call(
                $context->lookupFunction('__value__toLong'),
                JitValueBox::valuePtrFromVariable($context, $flagsSlot)
            );

        $ht = self::htPtr($context, $obj);
        $logical = 'PHPCompiler\\ext\\standard\\SerializeSplArrayNestedJitHelper::encodeWire';
        $saved = BasicBlockHelper::tryGetInsertBlock($context);
        \PHPCompiler\JIT\JitVmHelperLink::ensureCompiled(
            $context,
            '/ext/standard/SerializeSplArrayNestedJitHelper.php',
            [$logical],
            '#33625'
        );
        BasicBlockHelper::restoreInsertBlock($context, $saved);
        $fn = \PHPCompiler\JIT\JitVmHelperLink::lookupCompiled($context, $logical, '#33625');
        $strMap = $context->structFieldMap['__string__'];
        $classLen = $context->builder->load(
            $context->builder->structGep($classNameStr, $strMap['length'])
        );
        $args = [
            \PHPCompiler\JIT\JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $classNameStr,
                $fn->getParam(0)->typeOf()
            ),
            \PHPCompiler\JIT\JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $classLen,
                $fn->getParam(1)->typeOf()
            ),
            \PHPCompiler\JIT\JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $flags,
                $fn->getParam(2)->typeOf()
            ),
            \PHPCompiler\JIT\JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $ht,
                $fn->getParam(3)->typeOf()
            ),
        ];
        $raw = $context->builder->call($fn, ...$args);
        $strPtr = $context->getTypeFromString('__string__*');

        return \PHPCompiler\JIT\JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $strPtr);
    }

    /** Expose object load for {@see \PHPCompiler\ext\standard\JitSerialize} (#33625). */
    public static function loadObjectPtr(Context $context, JITVariable $receiver): Value
    {
        return self::loadObject($context, $receiver);
    }

    /**
     * php-src ArrayObject/ArrayIterator::__unserialize bag under thin AOT (#33636).
     *
     * Restores bag storage into `__spl_ht` and `__flags`. Does not overwrite
     * slot 0 with firstIntProp (that corrupted the HT pointer — SIGSEGV on json_encode).
     * String keys via UnserializeSplArrayFillNestedJitHelper (#33636 / #33663 / #33670
     * float/bool/null); packed int keys via UnserializeSplArrayFillIntKeyNestedJitHelper (#33654).
     *
     * Prefer helper-runtime (avoid PHP_COMPILER_HELPER_RUNTIME_O=0) — peer #32925.
     * NestedJIT helpers stay tiny and split across TUs (large bodies blank under NestedJIT).
     */
    public static function compileUnserializeRestore(
        Context $context,
        Value $obj,
        Value $payloadString,
        string $className = self::CLASS_NAME
    ): void {
        \PHPCompiler\JIT\Builtin\StringUnserialize::ensureLinked($context);
        $internals = [
            new \PHPCompiler\ext\standard\phpc_native_ht_alloc(),
            new \PHPCompiler\ext\standard\phpc_native_ht_set_string_key(),
            new \PHPCompiler\ext\standard\phpc_native_ht_set_string_key_long(),
            new \PHPCompiler\ext\standard\phpc_native_ht_set_string_key_bool(),
            new \PHPCompiler\ext\standard\phpc_native_ht_set_string_key_null(),
            new \PHPCompiler\ext\standard\phpc_native_ht_set_string_key_double_str(),
            new \PHPCompiler\ext\standard\phpc_native_ht_set_string_at(),
            new \PHPCompiler\ext\standard\phpc_native_ht_set_long_at(),
            new \PHPCompiler\ext\standard\phpc_native_ht_set_null_at(),
        ];
        foreach ($internals as $internal) {
            $lc = strtolower($internal->getName());
            $existing = $context->functionProxies[$lc] ?? null;
            if (null === $existing || $existing instanceof \PHPCompiler\JIT\Call\ExternalMethod) {
                $context->functionProxies[$lc] = $internal;
            }
        }
        $ht = self::htPtr($context, $obj);
        $findLogical = 'PHPCompiler\\ext\\standard\\UnserializeSplArrayFindNestedJitHelper::findStorage';
        $fillLogical = 'PHPCompiler\\ext\\standard\\UnserializeSplArrayFillNestedJitHelper::fillAt';
        $fillIntLogical = 'PHPCompiler\\ext\\standard\\UnserializeSplArrayFillIntKeyNestedJitHelper::fillAt';
        $flagsLogical = 'PHPCompiler\\ext\\standard\\UnserializeSplArrayFlagsNestedJitHelper::parseFlags';
        $saved = BasicBlockHelper::tryGetInsertBlock($context);
        \PHPCompiler\JIT\JitVmHelperLink::ensureCompiled(
            $context,
            '/ext/standard/UnserializeSplArrayFindNestedJitHelper.php',
            [$findLogical],
            '#33636'
        );
        BasicBlockHelper::restoreInsertBlock($context, $saved);
        $saved = BasicBlockHelper::tryGetInsertBlock($context);
        \PHPCompiler\JIT\JitVmHelperLink::ensureCompiled(
            $context,
            '/ext/standard/UnserializeSplArrayFillNestedJitHelper.php',
            [$fillLogical],
            '#33636'
        );
        BasicBlockHelper::restoreInsertBlock($context, $saved);
        $saved = BasicBlockHelper::tryGetInsertBlock($context);
        \PHPCompiler\JIT\JitVmHelperLink::ensureCompiled(
            $context,
            '/ext/standard/UnserializeSplArrayFillIntKeyNestedJitHelper.php',
            [$fillIntLogical],
            '#33654'
        );
        BasicBlockHelper::restoreInsertBlock($context, $saved);
        $saved = BasicBlockHelper::tryGetInsertBlock($context);
        \PHPCompiler\JIT\JitVmHelperLink::ensureCompiled(
            $context,
            '/ext/standard/UnserializeSplArrayFlagsNestedJitHelper.php',
            [$flagsLogical],
            '#33636'
        );
        BasicBlockHelper::restoreInsertBlock($context, $saved);

        $findFn = \PHPCompiler\JIT\JitVmHelperLink::lookupCompiled($context, $findLogical, '#33636');
        $fillFn = \PHPCompiler\JIT\JitVmHelperLink::lookupCompiled($context, $fillLogical, '#33636');
        $fillIntFn = \PHPCompiler\JIT\JitVmHelperLink::lookupCompiled($context, $fillIntLogical, '#33654');
        $flagsFn = \PHPCompiler\JIT\JitVmHelperLink::lookupCompiled($context, $flagsLogical, '#33636');
        $i64 = $context->getTypeFromString('int64');
        $payloadOwned = self::nestedJitOwnedString($context, $payloadString);

        $findOffRaw = $context->builder->call(
            $findFn,
            \PHPCompiler\JIT\JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $payloadOwned,
                $findFn->getParam(0)->typeOf()
            )
        );
        $findOff = \PHPCompiler\JIT\JitNestedHelperCoerce::coerceBridgeResult($context, $findOffRaw, $i64);
        $parent = BasicBlockHelper::parentFunction($context);
        $bbFill = $parent->appendBasicBlock('ao_unser_fill');
        $bbFlags = $parent->appendBasicBlock('ao_unser_flags');
        $bbDone = $parent->appendBasicBlock('ao_unser_done');
        $found = $context->builder->icmp(
            Builder::INT_SGE,
            $findOff,
            $i64->constInt(0, false)
        );
        $context->builder->branchIf($found, $bbFill, $bbFlags);

        $context->builder->positionAtEnd($bbFill);
        $destI64 = \PHPCompiler\JIT\JitNestedHelperCoerce::ptrToI64($context, $ht);
        // String-key bags (#33636); packed int-key bags (#33654) — each no-ops on the other shape.
        $context->builder->call(
            $fillFn,
            \PHPCompiler\JIT\JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $destI64,
                $fillFn->getParam(0)->typeOf()
            ),
            \PHPCompiler\JIT\JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $payloadOwned,
                $fillFn->getParam(1)->typeOf()
            ),
            \PHPCompiler\JIT\JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $findOff,
                $fillFn->getParam(2)->typeOf()
            )
        );
        $context->builder->call(
            $fillIntFn,
            \PHPCompiler\JIT\JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $destI64,
                $fillIntFn->getParam(0)->typeOf()
            ),
            \PHPCompiler\JIT\JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $payloadOwned,
                $fillIntFn->getParam(1)->typeOf()
            ),
            \PHPCompiler\JIT\JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $findOff,
                $fillIntFn->getParam(2)->typeOf()
            )
        );
        $context->builder->branch($bbFlags);

        $context->builder->positionAtEnd($bbFlags);
        $flagsRaw = $context->builder->call(
            $flagsFn,
            \PHPCompiler\JIT\JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $payloadOwned,
                $flagsFn->getParam(0)->typeOf()
            )
        );
        $flags = \PHPCompiler\JIT\JitNestedHelperCoerce::coerceBridgeResult($context, $flagsRaw, $i64);
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($obj, $className, self::PROP_FLAGS),
            new JITVariable($context, JITVariable::TYPE_NATIVE_LONG, JITVariable::KIND_VALUE, $flags),
            JITVariable::TYPE_NATIVE_LONG
        );
        if ('ArrayObject' === $className) {
            self::storeDefaultIteratorClassSlots($context, $obj);
        }
        $context->builder->branch($bbDone);
        $context->builder->positionAtEnd($bbDone);
    }

    /** Default iteratorClass=ArrayIterator after unserialize when bag i:3 is N (#33636). */
    private static function storeDefaultIteratorClassSlots(Context $context, Value $obj): void
    {
        $objectType = $context->type->object;
        $name = 'ArrayIterator';
        $classId = $objectType->lookup($name);
        $nameStr = $context->builder->load($context->constantStringFromString($name));
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, self::CLASS_NAME, self::PROP_ITERATOR_CLASS),
            new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $nameStr),
            JITVariable::TYPE_STRING
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, self::CLASS_NAME, self::PROP_ITERATOR_CLASS_ID),
            new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_LONG,
                JITVariable::KIND_VALUE,
                $context->getTypeFromString('int64')->constInt($classId, false)
            ),
            JITVariable::TYPE_NATIVE_LONG
        );
    }

    /**
     * Owned `__string__*` copy for NestedJIT PHP string params (#24137 / #33636).
     */
    private static function nestedJitOwnedString(Context $context, Value $payload): Value
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $separated = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $payload
        );
        $slot = BasicBlockHelper::entryAlloca($context, $strPtr);
        $context->builder->store($separated, $slot);
        $loaded = $context->builder->load($slot);
        $map = $context->structFieldMap['__string__'];
        $i8p = $context->getTypeFromString('int8*');
        $len = $context->builder->call($context->lookupFunction('__string__strlen'), $loaded);
        $src = $context->builder->pointerCast(
            $context->builder->structGep($loaded, $map['value']),
            $i8p
        );
        $copy = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $src
        );
        $context->refcount->disableRefcount($copy);

        return $copy;
    }

    /**
     * php-src zim_ArrayObject_getFlags / zim_ArrayIterator_getFlags — read `__flags` (#33616).
     * Construct already persists the slot for ARRAY_AS_PROPS (#33061); thin AOT lacked proxies.
     */
    public static function compileGetFlags(
        Context $context,
        JITVariable $receiver,
        string $className = self::CLASS_NAME
    ): Value {
        $obj = self::loadObject($context, $receiver);
        $flagsSlot = $context->type->object->propertyFetch($obj, $className, self::PROP_FLAGS);
        $flags = JITVariable::TYPE_NATIVE_LONG === $flagsSlot->type
            ? $context->helper->loadValue($flagsSlot)
            : $context->builder->call(
                $context->lookupFunction('__value__toLong'),
                JitValueBox::valuePtrFromVariable($context, $flagsSlot)
            );
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            JitValueBox::pointer($context, $slot),
            $flags
        );

        return $slot;
    }

    /**
     * php-src zim_ArrayObject_setFlags / zim_ArrayIterator_setFlags — store `__flags` (#33616).
     * ZEND_PARSE_PARAMETERS_START(1, 1) Z_PARAM_LONG — soft-null coerce via JitLongArg (#31696).
     */
    public static function compileSetFlags(
        Context $context,
        JITVariable $receiver,
        JITVariable $flagsArg,
        string $className = self::CLASS_NAME,
        string $function = 'ArrayObject::setFlags'
    ): Value {
        $obj = self::loadObject($context, $receiver);
        $flags = JitLongArg::lower($context, $flagsArg, $function.'() flags');
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($obj, $className, self::PROP_FLAGS),
            new JITVariable($context, JITVariable::TYPE_NATIVE_LONG, JITVariable::KIND_VALUE, $flags),
            JITVariable::TYPE_NATIVE_LONG
        );

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

    /** php-src spl_array_object_sort — always returns true on success (#19802). */
    private static function trueResult(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(true));

        return $slot;
    }
}

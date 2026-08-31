<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\WeakRefNative;
use PHPCompiler\JIT\Builtin\WeakRefRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\DatePeriodForeachSnapshot;
use PHPCompiler\JIT\IteratorProtocolHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\ObjectPropertyForeachHelper;
use PHPCompiler\JIT\SimpleXmlForeachSnapshot;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\Variable as JitVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * SSOT for JIT foreach hashtable / SplObjectStorage / WeakMap lowering (Zend zend_execute.c, #10080).
 *
 * Shared by foreach TYPE_ITER_* opcodes and ext/standard iterator builtins.
 *
 * php-src: Zend/zend_execute.c — ZEND_FE_FETCH_R array branch
 *
 * JIT trampoline: {@see \PHPCompiler\JIT\IteratorHelper}
 */

final class VmIteratorForeach
{
    private const FOREACH_ITERATOR_BYREF_ERROR = 'An iterator cannot be used with foreach by reference';

    private static function icmpUltSizeT(Context $context, \PHPLLVM\Value $left, \PHPLLVM\Value $right): \PHPLLVM\Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        if ('size_t' !== $context->getStringFromType($left->typeOf())) {
            $left = $context->builder->truncOrBitCast($left, $sizeT);
        }
        if ('size_t' !== $context->getStringFromType($right->typeOf())) {
            $right = $context->builder->truncOrBitCast($right, $sizeT);
        }
        return $context->builder->icmp(Builder::INT_ULT, $left, $right);
    }

    private static function usesObjectKeys(?string $containerUserType): bool
    {
        return null !== $containerUserType
            && 'splobjectstorage' === strtolower($containerUserType);
    }

    /** HT-backed SPL — foreach walks `__spl_ht` (#26783, #26775, #27311). */
    private static function usesArrayIteratorHt(?string $containerUserType): bool
    {
        return SplOuterIteratorHt::isHtBacked($containerUserType);
    }

    /**
     * IteratorAggregate-only (getIterator, no rewind): unwrap then walk inner `__spl_ht` (#26785).
     *
     * Thin AOT without any Iterator::rewind in the unit cannot take the method-protocol path, and
     * must not fall through to casting the aggregate object pointer as a hashtable (segfault).
     */
    private static function canLowerAggregateInnerHt(
        Context $context,
        JitVariable $array,
        ?string $containerUserType
    ): bool {
        if (null === $containerUserType || '' === $containerUserType) {
            return false;
        }
        $classLc = strtolower(ltrim($containerUserType, '\\'));
        if ('object' === $classLc || self::usesArrayIteratorHt($containerUserType) || self::usesObjectKeys($containerUserType)) {
            return false;
        }
        if ($array->type & JitVariable::IS_NATIVE_ARRAY || JitVariable::TYPE_HASHTABLE === $array->type) {
            return false;
        }
        if (JitVariable::TYPE_OBJECT !== $array->type && JitVariable::TYPE_VALUE !== $array->type) {
            return false;
        }
        if (!$context->functionIsRegistered($classLc.'::getiterator')) {
            return false;
        }
        // Full Iterator (rewind on the same class) keeps the method-protocol path.
        if ($context->functionIsRegistered($classLc.'::rewind')) {
            return false;
        }
        // DOM IteratorAggregate classes return InternalIterator, not ArrayIterator —
        // no __spl_ht on the inner object (php-src ext/dom/php_dom.stub.php; #32707).
        if (self::isDomIteratorAggregate($classLc)) {
            return false;
        }
        // User getIterator() that yields returns a Generator — no `__spl_ht` either.
        // Walking the Generator as an ArrayIterator HT SIGSEGVs (#34980; Zend/zend_interfaces.c).
        $getItLc = $classLc.'::getiterator';
        if (null !== \PHPCompiler\JIT\GeneratorHelper::creatorResumeName($context, $getItLc)) {
            return false;
        }

        return true;
    }

    /** DOM IteratorAggregate classes whose getIterator() returns InternalIterator, not ArrayIterator. */
    private static function isDomIteratorAggregate(string $classLc): bool
    {
        return \PHPCompiler\ext\dom\JitDomNodeListForeachSnapshot::isDomNodeListForeach($classLc);
    }

    /**
     * IteratorAggregate whose getIterator() is a Generator — unwrap then Generator foreach (#34980).
     */
    private static function canLowerAggregateGenerator(
        Context $context,
        JitVariable $array,
        ?string $containerUserType
    ): bool {
        if (null === $containerUserType || '' === $containerUserType) {
            return false;
        }
        $classLc = strtolower(ltrim($containerUserType, '\\'));
        if ('object' === $classLc || self::usesArrayIteratorHt($containerUserType) || self::usesObjectKeys($containerUserType)) {
            return false;
        }
        if ($array->type & JitVariable::IS_NATIVE_ARRAY || JitVariable::TYPE_HASHTABLE === $array->type) {
            return false;
        }
        if (JitVariable::TYPE_OBJECT !== $array->type && JitVariable::TYPE_VALUE !== $array->type) {
            return false;
        }
        // Generator methods are not registered as ordinary proxies (emitCreateFromCall path).
        if ($context->functionIsRegistered($classLc.'::rewind')) {
            return false;
        }
        $getItLc = $classLc.'::getiterator';

        return null !== \PHPCompiler\JIT\GeneratorHelper::creatorResumeName($context, $getItLc);
    }

    /**
     * Load the stored getIterator() receiver and hydrate it as the Aggregate's Generator (#34980).
     */
    private static function loadAggregateGeneratorReceiver(
        Context $context,
        JitVariable $slotKey
    ): JitVariable {
        $key = $context->foreachSlotMapKey($slotKey);
        $resume = $context->foreachAggregateGeneratorResume[$key]
            ?? throw new \LogicException('foreachAggregateGeneratorResume missing for slot');
        $inner = IteratorProtocolHelper::loadReceiver($context, $slotKey);
        $inner->classUserType = 'Generator';
        $inner->generatorResumeName = $resume;
        $inner->generatorStatePtr = null;
        \PHPCompiler\JIT\GeneratorHelper::hydrateGeneratorMetadata($context, $inner);

        return $inner;
    }

    private static function hashtableFromAggregateInner(Context $context, JitVariable $slotKey): JitVariable
    {
        $receiver = IteratorProtocolHelper::loadReceiver($context, $slotKey);

        return $context->type->object->splBackingHashtable($receiver);
    }

    private static function initHashtableIndex(Context $context, JitVariable $slotKey): void
    {
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $invalid = $context->builder->sub($zero, $one);
        $context->builder->store($invalid, self::indexSlot($context, $slotKey));
    }

    /** SplStack LIFO — start one past the last packed slot (#28705). */
    private static function initHashtableIndexReverse(
        Context $context,
        JitVariable $array,
        JitVariable $slotKey
    ): void {
        $ht = $context->helper->loadValue($array);
        $map = $context->structFieldMap['__hashtable__'];
        $nextFree = $context->builder->load($context->builder->structGep($ht, $map['nextFreeElement']));
        $context->builder->store($nextFree, self::indexSlot($context, $slotKey));
    }

    /** SplDoublyLinkedList only — Queue/Stack LIFO/FIFO are IT_MODE_FIX (#33987). */
    private static function isDllistRuntimeLifoCandidate(?string $containerUserType): bool
    {
        if (null === $containerUserType || '' === $containerUserType) {
            return false;
        }

        return 'spldoublylinkedlist' === strtolower(ltrim($containerUserType, '\\'));
    }

    /**
     * Reset packed walk direction from `__spl_flags` & IT_MODE_LIFO (#33987).
     * Stores a runtime i1 so valid() can dual-path without compile-time mode knowledge.
     */
    private static function initHashtableIndexMaybeReverseFromFlags(
        Context $context,
        JitVariable $htVar,
        JitVariable $slotKey,
        string $containerUserType
    ): void {
        $mapKey = $context->foreachSlotMapKey($slotKey);
        $i1 = $context->getTypeFromString('int1');
        $i64 = $context->getTypeFromString('int64');
        if (!isset($context->foreachRuntimeReverseSlots[$mapKey])) {
            $context->foreachRuntimeReverseSlots[$mapKey] = BasicBlockHelper::entryAlloca($context, $i1);
        }
        $revSlot = $context->foreachRuntimeReverseSlots[$mapKey];
        $receiver = JitVariable::TYPE_OBJECT === $slotKey->type
            ? $slotKey
            : VmIteratorProtocol::normalizeObjectReceiver($context, $slotKey);
        $obj = $context->helper->loadValue($receiver);
        $className = ltrim($containerUserType, '\\');
        $flags = SplDllistJitHelper::loadFlags($context, $obj, $className);
        $lifoBit = $context->builder->and(
            $flags,
            $i64->constInt(SplDllistJitHelper::IT_MODE_LIFO, false)
        );
        $isLifo = $context->builder->icmp(Builder::INT_NE, $lifoBit, $i64->constInt(0, false));
        $context->builder->store($isLifo, $revSlot);

        $fn = BasicBlockHelper::parentFunction($context);
        $lifoBb = $fn->appendBasicBlock('foreach_dll_lifo_init');
        $fifoBb = $fn->appendBasicBlock('foreach_dll_fifo_init');
        $doneBb = $fn->appendBasicBlock('foreach_dll_mode_done');
        $context->builder->branchIf($isLifo, $lifoBb, $fifoBb);

        $context->builder->positionAtEnd($lifoBb);
        self::initHashtableIndexReverse($context, $htVar, $slotKey);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($fifoBb);
        self::initHashtableIndex($context, $slotKey);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
    }

    private static function usesWeakMapHashtable(?string $containerUserType): bool
    {
        return null !== $containerUserType
            && 'weakmap' === strtolower($containerUserType);
    }

    /**
     * Borrowed {@see JitVariable::$objectPropertySlot} for external CFG / compiler array fields (#848).
     */
    private static function hashtableFromObjectPropertySlot(Context $context, JitVariable $array): ?JitVariable
    {
        if (null === $array->objectPropertySlot || null === $array->objectPropertyType) {
            return null;
        }
        if (JitVariable::TYPE_HASHTABLE === $array->objectPropertyType) {
            $loaded = $context->builder->load($array->objectPropertySlot);
            $htPtr = $context->builder->pointerCast(
                $loaded,
                $context->getTypeFromString('__hashtable__*')
            );

            return new JitVariable(
                $context,
                JitVariable::TYPE_HASHTABLE,
                JitVariable::KIND_VALUE,
                $htPtr
            );
        }
        if (JitVariable::TYPE_VALUE === $array->objectPropertyType) {
            $valueType = $context->getTypeFromString('__value__');
            $storage = BasicBlockHelper::entryAlloca($context, $valueType);
            $valueMap = $context->structFieldMap['__value__'];
            $context->builder->store(
                $context->getTypeFromString('int8')->constInt(JitVariable::TYPE_NULL, false),
                $context->builder->structGep($storage, $valueMap['type'])
            );
            $context->builder->call(
                $context->lookupFunction('__object__load_value_slot'),
                $array->objectPropertySlot,
                $storage
            );
            $ht = $context->builder->call(
                $context->lookupFunction('__value__readHashtable'),
                JitValueBox::pointer($context, $storage)
            );

            return new JitVariable(
                $context,
                JitVariable::TYPE_HASHTABLE,
                JitVariable::KIND_VALUE,
                $ht
            );
        }

        return null;
    }

    /**
     * Untyped `__value__` foreach container: arrays → readHashtable; HT-backed SPL objects
     * (runtime unserialize without classUserType) → splBackingHashtable (#33665).
     *
     * Compile-time `unserialize('O:…')` is tagged in {@see \PHPCompiler\JIT::propagateUnserializeSplFixedArrayResultType};
     * `unserialize(serialize($x))` is not.
     */
    private static function hashtableFromUntypedValueBox(Context $context, JitVariable $array): JitVariable
    {
        $valPtr = JitValueBox::valuePtrFromVariable($context, $array);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valPtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(JitVariable::TYPE_OBJECT & 0x7f, false)
        );

        $fn = BasicBlockHelper::parentFunction($context);
        $objBb = $fn->appendBasicBlock('foreach_value_obj');
        $arrBb = $fn->appendBasicBlock('foreach_value_arr');
        $doneBb = $fn->appendBasicBlock('foreach_value_ht_done');
        $htPtrTy = $context->getTypeFromString('__hashtable__*');
        $htSlot = BasicBlockHelper::entryAlloca($context, $htPtrTy);
        $context->builder->branchIf($isObject, $objBb, $arrBb);

        $context->builder->positionAtEnd($objBb);
        $objPtr = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valPtr
        );
        $receiver = new JitVariable(
            $context,
            JitVariable::TYPE_OBJECT,
            JitVariable::KIND_VALUE,
            $objPtr
        );
        $classId = $context->type->object->readRuntimeClassId($objPtr);
        $htBacked = self::emitIsRuntimeHtBackedClassId($context, $classId);
        $splBb = $fn->appendBasicBlock('foreach_value_spl_ht');
        $objMissBb = $fn->appendBasicBlock('foreach_value_obj_miss');
        $context->builder->branchIf($htBacked, $splBb, $objMissBb);

        $context->builder->positionAtEnd($splBb);
        $splHt = $context->type->object->splBackingHashtable($receiver);
        $context->builder->store($context->helper->loadValue($splHt), $htSlot);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($objMissBb);
        // Non-HT object in VALUE box: keep legacy readHashtable (same SEGV risk as before).
        $missHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $valPtr
        );
        $context->builder->store($missHt, $htSlot);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($arrBb);
        $arrHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $valPtr
        );
        $context->builder->store($arrHt, $htSlot);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return new JitVariable(
            $context,
            JitVariable::TYPE_HASHTABLE,
            JitVariable::KIND_VALUE,
            $context->builder->load($htSlot)
        );
    }

    /**
     * OR of class-id equality for HT-backed SPL names registered in this module (#33665).
     */
    private static function emitIsRuntimeHtBackedClassId(Context $context, Value $classId): Value
    {
        $i1 = $context->getTypeFromString('int1');
        $acc = $i1->constInt(0, false);
        $names = SplOuterIteratorHt::classNamesLc();
        $names[] = 'splstack';
        $seen = [];
        foreach ($names as $lc) {
            if (isset($seen[$lc])) {
                continue;
            }
            $seen[$lc] = true;
            $id = $context->type->object->classIdForLowerName($lc);
            if (null === $id) {
                continue;
            }
            $match = $context->builder->icmp(
                Builder::INT_EQ,
                $classId,
                $context->constantFromInteger($id, 'int64')
            );
            $acc = $context->builder->or($acc, $match);
        }

        return $acc;
    }

    private static function asHashtable(Context $context, JitVariable $array, ?string $containerUserType): JitVariable
    {
        if (JitVariable::TYPE_HASHTABLE === $array->type) {
            return HashTableHelper::asDetachedHashtable($context, $array);
        }
        if ($array->type & JitVariable::IS_NATIVE_ARRAY) {
            return new JitVariable(
                $context,
                JitVariable::TYPE_HASHTABLE,
                JitVariable::KIND_VALUE,
                HashTableHelper::materializeNativeArrayForCall($context, $array)
            );
        }
        $fromPropertySlot = self::hashtableFromObjectPropertySlot($context, $array);
        if (null !== $fromPropertySlot) {
            return $fromPropertySlot;
        }
        // VALUE-boxed objects (common after `new` temps) must not go through __value__readHashtable (#26783).
        if (self::usesObjectKeys($containerUserType)
            || self::usesArrayIteratorHt($containerUserType)
            || self::usesWeakMapHashtable($containerUserType)
        ) {
            if (JitVariable::TYPE_OBJECT === $array->type || JitVariable::TYPE_VALUE === $array->type) {
                $receiver = JitVariable::TYPE_OBJECT === $array->type
                    ? $array
                    : VmIteratorProtocol::normalizeObjectReceiver($context, $array);
                if (self::usesWeakMapHashtable($containerUserType)) {
                    return $context->type->object->weakMapBackingHashtable($receiver);
                }

                return $context->type->object->splBackingHashtable($receiver);
            }
        }
        if (JitVariable::TYPE_VALUE === $array->type) {
            // Untyped VALUE: arrays use readHashtable; unserialize(serialize($ao)) leaves an
            // object box without classUserType — probe type byte / class id (#33665 / leftover #33654).
            return self::hashtableFromUntypedValueBox($context, $array);
        }
        if (JitVariable::TYPE_OBJECT === $array->type) {
            if (self::usesObjectKeys($containerUserType) || self::usesArrayIteratorHt($containerUserType)) {
                return $context->type->object->splBackingHashtable($array);
            }
            if (self::usesWeakMapHashtable($containerUserType)) {
                return $context->type->object->weakMapBackingHashtable($array);
            }

            if (JitVariable::KIND_VALUE === $array->kind || JitVariable::KIND_VARIABLE === $array->kind) {
                // Variadic packs (e.g. OpCode ...$ops) are lowered as __hashtable__* but typed as object.
                $ptr = JitVariable::KIND_VALUE === $array->kind
                    ? $array->value
                    : $context->builder->load($array->value);
                $htPtr = $context->builder->pointerCast(
                    $ptr,
                    $context->getTypeFromString('__hashtable__*')
                );

                return new JitVariable(
                    $context,
                    JitVariable::TYPE_HASHTABLE,
                    JitVariable::KIND_VALUE,
                    $htPtr
                );
            }

            throw new \LogicException(
                'foreach over objects is only supported for SplObjectStorage in this compiler build'
            );
        }
        throw new \LogicException(
            'foreach requires an array, got '.JitVariable::getStringType($array->type)
        );
    }

    private static function indexSlot(Context $context, JitVariable $slotKey): \PHPLLVM\Value
    {
        $key = $context->foreachSlotMapKey($slotKey);
        if (isset($context->foreachIndexSlots[$key])) {
            return $context->foreachIndexSlots[$key];
        }
        $sizeT = $context->getTypeFromString('size_t');
        $slot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->foreachIndexSlots[$key] = $slot;

        return $slot;
    }

    private static function objNodeSlot(Context $context, JitVariable $slotKey): \PHPLLVM\Value
    {
        $key = $context->foreachSlotMapKey($slotKey);
        if (isset($context->foreachObjNodeSlots[$key])) {
            return $context->foreachObjNodeSlots[$key];
        }
        $nodePtrType = $context->getTypeFromString('__objkey_node__*');
        $slot = BasicBlockHelper::entryAlloca($context, $nodePtrType);
        $context->foreachObjNodeSlots[$key] = $slot;

        return $slot;
    }

    public static function compileReset(Context $context, JitVariable $array, ?string $containerUserType = null): void
    {
        $slotKey = $array;
        // DOMNodeList / DOMNamedNodeMap: compile-time snapshot when available (#32707, #33082).
        if (\PHPCompiler\ext\dom\JitDomNodeListForeachSnapshot::isDomNodeListForeach($containerUserType)) {
            if (\PHPCompiler\ext\dom\JitDomNodeListForeachSnapshot::canLower($context, $array, $containerUserType)) {
                \PHPCompiler\ext\dom\JitDomNodeListForeachSnapshot::compileReset(
                    $context,
                    $array,
                    $slotKey,
                    $containerUserType
                );

                return;
            }
        }
        // SimpleXMLElement host-tree snapshot before object-property / Iterator stubs (#27535).
        if (SimpleXmlForeachSnapshot::canLower($array)) {
            SimpleXmlForeachSnapshot::compileReset($context, $array, $slotKey);

            return;
        }
        // Before object-property foreach — DatePeriod public props are not the iterator
        // (#26772 / #33744). rewind/clone remains AOT-unsafe.
        if (DatePeriodForeachSnapshot::canLower($array)) {
            DatePeriodForeachSnapshot::compileReset($context, $array, $slotKey);

            return;
        }
        if (ObjectPropertyForeachHelper::canLower($context, $array, $containerUserType)) {
            ObjectPropertyForeachHelper::compileReset($context, $array, $slotKey);

            return;
        }
        // IteratorAggregate::getIterator() that yields → Generator foreach (#34980).
        if (self::canLowerAggregateGenerator($context, $array, $containerUserType)) {
            $classLc = strtolower(ltrim((string) $containerUserType, '\\'));
            $getItLc = $classLc.'::getiterator';
            $resume = \PHPCompiler\JIT\GeneratorHelper::creatorResumeName($context, $getItLc);
            if (null === $resume) {
                throw new \LogicException('Aggregate Generator foreach missing creator resume');
            }
            // Generator methods are not Function proxies — mint the Generator like FUNCCALL (#34980).
            $gen = VmGenerator::emitCreateFromCallContext($context, $resume);
            IteratorProtocolHelper::storeReceiver($context, $slotKey, $gen);
            $context->foreachAggregateGeneratorResume[$context->foreachSlotMapKey($slotKey)] = $resume;
            \PHPCompiler\JIT\GeneratorHelper::compileIterReset($context, $gen);

            return;
        }
        // IteratorAggregate → getIterator() → ArrayIterator `__spl_ht` (#26785).
        if (self::canLowerAggregateInnerHt($context, $array, $containerUserType)) {
            $receiver = IteratorProtocolHelper::resolveForeachReceiver($context, $array, $containerUserType);
            IteratorProtocolHelper::storeReceiver($context, $slotKey, $receiver);
            $context->foreachAggregateInnerHtSlots[$context->foreachSlotMapKey($slotKey)] = true;
            self::initHashtableIndex($context, $slotKey);

            return;
        }
        if (IteratorProtocolHelper::canLowerIteratorProtocol($context, $array, $containerUserType)) {
            IteratorProtocolHelper::compileForeachReset($context, $array, $slotKey, $containerUserType);

            return;
        }
        if (null !== $containerUserType
            && 'limititerator' === strtolower(ltrim($containerUserType, '\\'))) {
            $receiver = IteratorProtocolHelper::normalizeObjectReceiver($context, $array);
            LimitIteratorJitHelper::compileRewindOobCheck($context, $receiver);
        }
        // WeakMap before generic asHashtable — string-key HT walk, not ObjectProperty (#33860).
        if (self::usesWeakMapHashtable($containerUserType)) {
            WeakRefRuntime::ensureLinked($context);
            WeakRefNative::registerDeclarations($context);
            self::initHashtableIndex($context, $slotKey);

            return;
        }
        $array = self::asHashtable($context, $array, $containerUserType);
        if (self::usesObjectKeys($containerUserType)) {
            // SplObjectStorage: walk objKeys; index tracks Zend key() (#28707).
            $nodePtrType = $context->getTypeFromString('__objkey_node__*');
            $context->builder->store(
                $nodePtrType->constNull(),
                self::objNodeSlot($context, $slotKey)
            );
            self::initHashtableIndex($context, $slotKey);
            // Match Zend FE_RESET → rewind(): getInfo/current share intern->index (#35030).
            $sizeT = $context->getTypeFromString('size_t');
            SplObjectStorageJitHelper::syncIterPosFromForeachIndex(
                $context,
                $slotKey,
                $sizeT->constInt(0, false)
            );

            return;
        }
        // SplDoublyLinkedList: honour `__spl_flags` IT_MODE_LIFO at reset (#33987 / #28705).
        if (null !== $containerUserType && self::isDllistRuntimeLifoCandidate($containerUserType)) {
            self::initHashtableIndexMaybeReverseFromFlags($context, $array, $slotKey, $containerUserType);

            return;
        }
        if (SplOuterIteratorHt::isReverseHtWalk($containerUserType)) {
            $context->foreachReverseHtSlots[$context->foreachSlotMapKey($slotKey)] = true;
            self::initHashtableIndexReverse($context, $array, $slotKey);

            return;
        }
        self::initHashtableIndex($context, $slotKey);
    }

    public static function compileValid(
        Context $context,
        JitVariable $array,
        ?string $containerUserType = null
    ): \PHPLLVM\Value {
        $slotKey = $array;
        if (isset($context->foreachDomNodeListSlots[$context->foreachSlotMapKey($slotKey)])) {
            $snapshotHt = $context->foreachDomNodeListSlots[$context->foreachSlotMapKey($slotKey)];

            return self::compileValidHashtable($context, $snapshotHt, $slotKey);
        }
        if (isset($context->foreachDatePeriodSnapshotHts[$context->foreachSlotMapKey($slotKey)])) {
            $ht = DatePeriodForeachSnapshot::hashtableFor($context, $slotKey);

            return self::compileValidHashtable($context, $ht, $slotKey);
        }
        if (ObjectPropertyForeachHelper::canLower($context, $array, $containerUserType)) {
            return ObjectPropertyForeachHelper::compileValid($context, $slotKey, $containerUserType);
        }
        if (isset($context->foreachAggregateGeneratorResume[$context->foreachSlotMapKey($slotKey)])) {
            return \PHPCompiler\JIT\GeneratorHelper::compileIterValid(
                $context,
                self::loadAggregateGeneratorReceiver($context, $slotKey)
            );
        }
        if (isset($context->foreachAggregateInnerHtSlots[$context->foreachSlotMapKey($slotKey)])) {
            return self::compileValidHashtable(
                $context,
                self::hashtableFromAggregateInner($context, $slotKey),
                $slotKey
            );
        }
        if (IteratorProtocolHelper::canLowerIteratorProtocol($context, $array, $containerUserType)) {
            return IteratorProtocolHelper::compileForeachValid($context, $slotKey, $containerUserType);
        }
        $array = self::asHashtable($context, $array, $containerUserType);
        if (self::usesObjectKeys($containerUserType)) {
            return self::compileValidObjectKeys($context, $array, $slotKey);
        }

        return self::compileValidHashtable($context, $array, $slotKey);
    }

    private static function compileValidObjectKeys(
        Context $context,
        JitVariable $array,
        JitVariable $slotKey
    ): \PHPLLVM\Value {
        $ht = $context->helper->loadValue($array);
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__objkey_node__'];
        $nodePtrType = $context->getTypeFromString('__objkey_node__*');
        $i1 = $context->getTypeFromString('int1');
        $walkSlot = self::objNodeSlot($context, $slotKey);
        $current = $context->builder->load($walkSlot);
        $fn = $context->builder->getInsertBlock()->getParent();
        $init = $fn->appendBasicBlock('foreach_obj_init');
        $advance = $fn->appendBasicBlock('foreach_obj_advance');
        $check = $fn->appendBasicBlock('foreach_obj_check');
        $found = $fn->appendBasicBlock('foreach_obj_found');
        $empty = $fn->appendBasicBlock('foreach_obj_empty');
        $merge = $fn->appendBasicBlock('foreach_obj_merge');
        $isFirst = $context->builder->icmp(
            Builder::INT_EQ,
            $current,
            $nodePtrType->constNull()
        );
        $context->builder->branchIf($isFirst, $init, $advance);

        $sizeT = $context->getTypeFromString('size_t');
        $context->builder->positionAtEnd($init);
        $head = $context->builder->load($context->builder->structGep($ht, $map['objKeys']));
        $context->builder->store($head, $walkSlot);
        // SplObjectStorage::key() is the 0-based insertion index (#28707 / php-src).
        $zeroIdx = $sizeT->constInt(0, false);
        $context->builder->store($zeroIdx, self::indexSlot($context, $slotKey));
        // Keep __spl_iter_pos in lockstep so getInfo()/current() see the foreach cursor (#35030).
        SplObjectStorageJitHelper::syncIterPosFromForeachIndex($context, $slotKey, $zeroIdx);
        $context->builder->branch($check);

        $context->builder->positionAtEnd($advance);
        $next = $context->builder->load($context->builder->structGep($current, $nodeMap['next']));
        $context->builder->store($next, $walkSlot);
        $idx = $context->builder->load(self::indexSlot($context, $slotKey));
        $nextIdx = $context->builder->addNoSignedWrap($idx, $sizeT->constInt(1, false));
        $context->builder->store($nextIdx, self::indexSlot($context, $slotKey));
        SplObjectStorageJitHelper::syncIterPosFromForeachIndex($context, $slotKey, $nextIdx);
        $context->builder->branch($check);

        $context->builder->positionAtEnd($check);
        $node = $context->builder->load($walkSlot);
        $hasNode = $context->builder->icmp(
            Builder::INT_NE,
            $node,
            $nodePtrType->constNull()
        );
        $context->builder->branchIf($hasNode, $found, $empty);

        $context->builder->positionAtEnd($found);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($empty);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        $result = $context->builder->phi($i1);
        $result->addIncoming($i1->constInt(1, false), $found);
        $result->addIncoming($i1->constInt(0, false), $empty);

        return $result;
    }

    private static function compileValidHashtable(Context $context, JitVariable $array, JitVariable $slotKey): \PHPLLVM\Value
    {
        $mapKey = $context->foreachSlotMapKey($slotKey);
        if (isset($context->foreachRuntimeReverseSlots[$mapKey])) {
            return self::compileValidHashtableMaybeReverse($context, $array, $slotKey);
        }
        if (isset($context->foreachReverseHtSlots[$mapKey])) {
            return self::compileValidHashtableReverse($context, $array, $slotKey);
        }

        return self::compileValidHashtableForward($context, $array, $slotKey);
    }

    /** Dual-path valid for ddl when `__spl_flags` LIFO is only known at runtime (#33987). */
    private static function compileValidHashtableMaybeReverse(
        Context $context,
        JitVariable $array,
        JitVariable $slotKey
    ): \PHPLLVM\Value {
        $mapKey = $context->foreachSlotMapKey($slotKey);
        $revFlag = $context->builder->load($context->foreachRuntimeReverseSlots[$mapKey]);
        $fn = BasicBlockHelper::parentFunction($context);
        $revBb = $fn->appendBasicBlock('foreach_valid_runtime_rev');
        $fwdBb = $fn->appendBasicBlock('foreach_valid_runtime_fwd');
        $merge = $fn->appendBasicBlock('foreach_valid_runtime_merge');
        $context->builder->branchIf($revFlag, $revBb, $fwdBb);

        $context->builder->positionAtEnd($revBb);
        $rev = self::compileValidHashtableReverse($context, $array, $slotKey);
        $revEnd = $context->builder->getInsertBlock();
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($fwdBb);
        $fwd = self::compileValidHashtableForward($context, $array, $slotKey);
        $fwdEnd = $context->builder->getInsertBlock();
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);
        $i1 = $context->getTypeFromString('int1');
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($rev, $revEnd);
        $phi->addIncoming($fwd, $fwdEnd);

        return $phi;
    }

    private static function packedPrefixEndUnset(Context $context): \PHPLLVM\Value
    {
        return $context->getTypeFromString('size_t')->constInt(\PHP_INT_MAX, false);
    }

    private static function usesInsertionOrderForeach(Context $context, \PHPLLVM\Value $ht, array $map): \PHPLLVM\Value
    {
        $i1 = $context->getTypeFromString('int1');
        $head = $context->builder->load($context->builder->structGep($ht, $map['strKeys']));
        $headNull = $context->builder->icmp(Builder::INT_EQ, $head, $head->typeOf()->constNull());
        $prefixEnd = $context->builder->load($context->builder->structGep($ht, $map['packedPrefixEnd']));
        $prefixUnset = $context->builder->icmp(
            Builder::INT_EQ,
            $prefixEnd,
            self::packedPrefixEndUnset($context)
        );

        return $context->builder->and(
            $context->builder->not($headNull),
            $context->builder->not($prefixUnset)
        );
    }

    private static function foreachStringKeyCount(Context $context, \PHPLLVM\Value $ht, array $map): \PHPLLVM\Value
    {
        $numElements = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $nextFree = $context->builder->load($context->builder->structGep($ht, $map['nextFreeElement']));

        return $context->builder->sub($numElements, $nextFree);
    }

    private static function compileValidHashtableForward(Context $context, JitVariable $array, JitVariable $slotKey): \PHPLLVM\Value
    {
        $ht = $context->helper->loadValue($array);
        $map = $context->structFieldMap['__hashtable__'];
        $fn = $context->builder->getInsertBlock()->getParent();
        $legacy = $fn->appendBasicBlock('foreach_valid_legacy');
        $insertion = $fn->appendBasicBlock('foreach_valid_insertion');
        $merge = $fn->appendBasicBlock('foreach_valid_mode_merge');
        $entry = $context->builder->getInsertBlock();
        $context->builder->branchIf(self::usesInsertionOrderForeach($context, $ht, $map), $insertion, $legacy);

        $context->builder->positionAtEnd($legacy);
        $legacyResult = self::compileValidHashtableForwardLegacy($context, $array, $slotKey);
        $legacyEnd = $context->builder->getInsertBlock();
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($insertion);
        $insertionResult = self::compileValidHashtableForwardInsertionOrder($context, $array, $slotKey);
        $insertionEnd = $context->builder->getInsertBlock();
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);
        $i1 = $context->getTypeFromString('int1');
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($legacyResult, $legacyEnd);
        $phi->addIncoming($insertionResult, $insertionEnd);

        return $phi;
    }

    private static function compileValidHashtableForwardLegacy(Context $context, JitVariable $array, JitVariable $slotKey): \PHPLLVM\Value
    {
        $ht = $context->helper->loadValue($array);
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $slot = self::indexSlot($context, $slotKey);
        $fn = $context->builder->getInsertBlock()->getParent();
        $packedHead = $fn->appendBasicBlock('foreach_packed_head');
        $packedBody = $fn->appendBasicBlock('foreach_packed_body');
        $strInit = $fn->appendBasicBlock('foreach_str_init');
        $strWalk = $fn->appendBasicBlock('foreach_str_walk');
        $found = $fn->appendBasicBlock('foreach_found');
        $empty = $fn->appendBasicBlock('foreach_empty');
        $merge = $fn->appendBasicBlock('foreach_valid_merge');
        $context->builder->branch($packedHead);

        // Packed: mirror VM HashTable::iterValid — skip UNDEFINED holes only, not null (#24261).
        $context->builder->positionAtEnd($packedHead);
        $idx = $context->builder->load($slot);
        $nextIdx = $context->builder->addNoSignedWrap($idx, $one);
        $context->builder->store($nextIdx, $slot);
        $nextFree = $context->builder->load($context->builder->structGep($ht, $map['nextFreeElement']));
        $inPacked = self::icmpUltSizeT($context, $nextIdx, $nextFree);
        $context->builder->branchIf($inPacked, $packedBody, $strInit);

        $context->builder->positionAtEnd($packedBody);
        // Zend FE_FETCH_R: skip unset holes only — null elements are valid iteration targets.
        // offsetIsSet treats TYPE_NULL like a hole (isset semantics) and skips forever (#24261).
        $entry = HashTableHelper::listEntryPointer($context, $ht, $nextIdx);
        $typeByte = $context->builder->load(
            $context->builder->structGep($entry, $context->structFieldMap['__value__']['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $isDefined = $context->builder->icmp(
            Builder::INT_NE,
            $typeByte,
            $i8->constInt(Variable::TYPE_UNDEFINED & 0xff, false)
        );
        $context->builder->branchIf($isDefined, $found, $packedHead);

        $context->builder->positionAtEnd($strInit);
        $strEntry = $fn->appendBasicBlock('foreach_str_entry');
        $context->builder->branch($strEntry);

        $context->builder->positionAtEnd($strEntry);
        // Packed index continues past nextFree into the string-key chain (#34977).
        // Using the raw index skipped the first nextFree string nodes whenever the
        // table also had packed elements (mixed [1,2,'x'=>3] / ArrayIterator).
        $ord = $context->builder->sub($context->builder->load($slot), $nextFree);
        $head = $context->builder->load($context->builder->structGep($ht, $map['strKeys']));
        $headNull = $context->builder->icmp(Builder::INT_EQ, $head, $head->typeOf()->constNull());
        $context->builder->branchIf($headNull, $empty, $strWalk);

        $context->builder->positionAtEnd($strWalk);
        $node = $context->builder->phi($head->typeOf());
        $node->addIncoming($head, $strEntry);
        $remaining = $context->builder->phi($sizeT);
        $remaining->addIncoming($ord, $strEntry);
        $atTarget = $context->builder->icmp(Builder::INT_EQ, $remaining, $zero);
        $strStep = $fn->appendBasicBlock('foreach_str_step');
        $context->builder->branchIf($atTarget, $found, $strStep);
        $context->builder->positionAtEnd($strStep);
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $nextNull = $context->builder->icmp(Builder::INT_EQ, $nextNode, $nextNode->typeOf()->constNull());
        $strAdvance = $fn->appendBasicBlock('foreach_str_advance');
        $context->builder->branchIf($nextNull, $empty, $strAdvance);
        $context->builder->positionAtEnd($strAdvance);
        $node->addIncoming($nextNode, $strAdvance);
        $remaining->addIncoming($context->builder->sub($remaining, $one), $strAdvance);
        $context->builder->branch($strWalk);

        $context->builder->positionAtEnd($found);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($empty);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        $result = $context->builder->phi($i1);
        $result->addIncoming($i1->constInt(1, false), $found);
        $result->addIncoming($i1->constInt(0, false), $empty);

        return $result;
    }

    /**
     * Zend insertion-order foreach: packed prefix, string keys, then late appends (#34977).
     */
    private static function compileValidHashtableForwardInsertionOrder(
        Context $context,
        JitVariable $array,
        JitVariable $slotKey
    ): \PHPLLVM\Value {
        $ht = $context->helper->loadValue($array);
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $slot = self::indexSlot($context, $slotKey);
        $fn = $context->builder->getInsertBlock()->getParent();
        $head = $fn->appendBasicBlock('foreach_ins_head');
        $prefixBody = $fn->appendBasicBlock('foreach_ins_prefix');
        $strBody = $fn->appendBasicBlock('foreach_ins_str');
        $lateBody = $fn->appendBasicBlock('foreach_ins_late');
        $found = $fn->appendBasicBlock('foreach_ins_found');
        $empty = $fn->appendBasicBlock('foreach_ins_empty');
        $merge = $fn->appendBasicBlock('foreach_ins_merge');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($slot);
        $linearPos = $context->builder->addNoSignedWrap($idx, $one);
        $context->builder->store($linearPos, $slot);
        $prefixEnd = $context->builder->load($context->builder->structGep($ht, $map['packedPrefixEnd']));
        $nextFree = $context->builder->load($context->builder->structGep($ht, $map['nextFreeElement']));
        $strCount = self::foreachStringKeyCount($context, $ht, $map);
        $totalPos = $context->builder->addNoSignedWrap($nextFree, $strCount);
        $pastEnd = $context->builder->icmp(Builder::INT_UGE, $linearPos, $totalPos);
        $inPrefix = self::icmpUltSizeT($context, $linearPos, $prefixEnd);
        $strEnd = $context->builder->addNoSignedWrap($prefixEnd, $strCount);
        $inStr = self::icmpUltSizeT($context, $linearPos, $strEnd);
        $routeStr = $fn->appendBasicBlock('foreach_ins_route_str');
        $routeLate = $fn->appendBasicBlock('foreach_ins_route_late');
        $context->builder->branchIf($pastEnd, $empty, $routeStr);
        $context->builder->positionAtEnd($routeStr);
        $context->builder->branchIf($inPrefix, $prefixBody, $routeLate);
        $context->builder->positionAtEnd($routeLate);
        $context->builder->branchIf($inStr, $strBody, $lateBody);

        $context->builder->positionAtEnd($prefixBody);
        $entry = HashTableHelper::listEntryPointer($context, $ht, $linearPos);
        $typeByte = $context->builder->load(
            $context->builder->structGep($entry, $context->structFieldMap['__value__']['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $isDefined = $context->builder->icmp(
            Builder::INT_NE,
            $typeByte,
            $i8->constInt(Variable::TYPE_UNDEFINED & 0xff, false)
        );
        $context->builder->branchIf($isDefined, $found, $head);

        $context->builder->positionAtEnd($lateBody);
        $lateOffset = $context->builder->sub(
            $context->builder->sub($linearPos, $prefixEnd),
            $strCount
        );
        $lateIdx = $context->builder->addNoSignedWrap($prefixEnd, $lateOffset);
        $lateEntry = HashTableHelper::listEntryPointer($context, $ht, $lateIdx);
        $lateType = $context->builder->load(
            $context->builder->structGep($lateEntry, $context->structFieldMap['__value__']['type'])
        );
        $lateDefined = $context->builder->icmp(
            Builder::INT_NE,
            $lateType,
            $i8->constInt(Variable::TYPE_UNDEFINED & 0xff, false)
        );
        $context->builder->branchIf($lateDefined, $found, $head);

        $context->builder->positionAtEnd($strBody);
        $strEntry = $fn->appendBasicBlock('foreach_ins_str_entry');
        $strWalk = $fn->appendBasicBlock('foreach_ins_str_walk');
        $context->builder->branch($strEntry);
        $context->builder->positionAtEnd($strEntry);
        $ord = $context->builder->sub($linearPos, $prefixEnd);
        $strHead = $context->builder->load($context->builder->structGep($ht, $map['strKeys']));
        $headNull = $context->builder->icmp(Builder::INT_EQ, $strHead, $strHead->typeOf()->constNull());
        $context->builder->branchIf($headNull, $empty, $strWalk);
        $context->builder->positionAtEnd($strWalk);
        $node = $context->builder->phi($strHead->typeOf());
        $node->addIncoming($strHead, $strEntry);
        $remaining = $context->builder->phi($sizeT);
        $remaining->addIncoming($ord, $strEntry);
        $atTarget = $context->builder->icmp(Builder::INT_EQ, $remaining, $zero);
        $strStep = $fn->appendBasicBlock('foreach_ins_str_step');
        $context->builder->branchIf($atTarget, $found, $strStep);
        $context->builder->positionAtEnd($strStep);
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $nextNull = $context->builder->icmp(Builder::INT_EQ, $nextNode, $nextNode->typeOf()->constNull());
        $strAdvance = $fn->appendBasicBlock('foreach_ins_str_advance');
        $context->builder->branchIf($nextNull, $empty, $strAdvance);
        $context->builder->positionAtEnd($strAdvance);
        $node->addIncoming($nextNode, $strAdvance);
        $remaining->addIncoming($context->builder->sub($remaining, $one), $strAdvance);
        $context->builder->branch($strWalk);

        $context->builder->positionAtEnd($found);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($empty);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        $result = $context->builder->phi($i1);
        $result->addIncoming($i1->constInt(1, false), $found);
        $result->addIncoming($i1->constInt(0, false), $empty);

        return $result;
    }

    /**
     * SplStack LIFO packed walk — decrement from nextFree toward 0 (#28705).
     *
     * Keys are storage indices (Zend: push 10/20/30 → k=2,1,0).
     */
    private static function compileValidHashtableReverse(
        Context $context,
        JitVariable $array,
        JitVariable $slotKey
    ): \PHPLLVM\Value {
        $ht = $context->helper->loadValue($array);
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $slot = self::indexSlot($context, $slotKey);
        $fn = $context->builder->getInsertBlock()->getParent();
        $head = $fn->appendBasicBlock('foreach_rev_head');
        $body = $fn->appendBasicBlock('foreach_rev_body');
        $found = $fn->appendBasicBlock('foreach_rev_found');
        $empty = $fn->appendBasicBlock('foreach_rev_empty');
        $merge = $fn->appendBasicBlock('foreach_rev_merge');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($slot);
        $hasMore = $context->builder->icmp(Builder::INT_UGT, $idx, $zero);
        $context->builder->branchIf($hasMore, $body, $empty);

        $context->builder->positionAtEnd($body);
        $nextIdx = $context->builder->sub($idx, $one);
        $context->builder->store($nextIdx, $slot);
        $entry = HashTableHelper::listEntryPointer($context, $ht, $nextIdx);
        $typeByte = $context->builder->load(
            $context->builder->structGep($entry, $context->structFieldMap['__value__']['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $isDefined = $context->builder->icmp(
            Builder::INT_NE,
            $typeByte,
            $i8->constInt(Variable::TYPE_UNDEFINED & 0xff, false)
        );
        $context->builder->branchIf($isDefined, $found, $head);

        $context->builder->positionAtEnd($found);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($empty);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        $result = $context->builder->phi($i1);
        $result->addIncoming($i1->constInt(1, false), $found);
        $result->addIncoming($i1->constInt(0, false), $empty);

        return $result;
    }

    public static function compileKey(
        Context $context,
        JitVariable $array,
        ?string $containerUserType = null
    ): JitVariable {
        $slotKey = $array;
        if (isset($context->foreachDomNodeListSlots[$context->foreachSlotMapKey($slotKey)])) {
            $snapshotHt = $context->foreachDomNodeListSlots[$context->foreachSlotMapKey($slotKey)];

            return self::compileKeyHashtable($context, $snapshotHt, $slotKey);
        }
        if (isset($context->foreachDatePeriodSnapshotHts[$context->foreachSlotMapKey($slotKey)])) {
            $ht = DatePeriodForeachSnapshot::hashtableFor($context, $slotKey);

            return self::compileKeyHashtable($context, $ht, $slotKey);
        }
        if (ObjectPropertyForeachHelper::canLower($context, $array, $containerUserType)) {
            return ObjectPropertyForeachHelper::compileKey($context, $slotKey, $containerUserType);
        }
        if (isset($context->foreachAggregateGeneratorResume[$context->foreachSlotMapKey($slotKey)])) {
            return \PHPCompiler\JIT\GeneratorHelper::compileIterKey(
                $context,
                self::loadAggregateGeneratorReceiver($context, $slotKey)
            );
        }
        if (isset($context->foreachAggregateInnerHtSlots[$context->foreachSlotMapKey($slotKey)])) {
            return self::compileKeyHashtable(
                $context,
                self::hashtableFromAggregateInner($context, $slotKey),
                $slotKey
            );
        }
        if (IteratorProtocolHelper::canLowerIteratorProtocol($context, $array, $containerUserType)) {
            return IteratorProtocolHelper::compileForeachKey($context, $slotKey, $containerUserType);
        }
        $array = self::asHashtable($context, $array, $containerUserType);
        if (self::usesObjectKeys($containerUserType)) {
            return self::compileKeyObject($context, $slotKey);
        }
        if (self::usesWeakMapHashtable($containerUserType)) {
            return self::compileKeyWeakMap($context, $array, $slotKey);
        }
        if (self::usesAppendIteratorKeys($containerUserType)) {
            return self::compileKeyAppendIterator($context, $slotKey);
        }
        if (self::usesRecursiveIteratorIteratorKeys($containerUserType)) {
            return self::compileKeyRecursiveIteratorIterator($context, $slotKey);
        }

        return self::compileKeyHashtable($context, $array, $slotKey);
    }

    private static function usesAppendIteratorKeys(?string $containerUserType): bool
    {
        return null !== $containerUserType
            && 'appenditerator' === strtolower(ltrim($containerUserType, '\\'));
    }

    private static function usesRecursiveIteratorIteratorKeys(?string $containerUserType): bool
    {
        return null !== $containerUserType
            && 'recursiveiteratoriterator' === strtolower(ltrim($containerUserType, '\\'));
    }

    /** Read original inner keys from AppendIterator `__spl_keys` (#27312). */
    private static function compileKeyAppendIterator(Context $context, JitVariable $slotKey): JitVariable
    {
        $receiver = JitVariable::TYPE_OBJECT === $slotKey->type
            ? $slotKey
            : VmIteratorProtocol::normalizeObjectReceiver($context, $slotKey);
        $keysHt = \PHPCompiler\JIT\Call\AppendIteratorMethod::keysHashtable($context, $receiver);
        $idx = $context->builder->load(self::indexSlot($context, $slotKey));
        $keyBox = HashTableHelper::readIndexedToValueBox(
            $context,
            $context->helper->loadValue($keysHt),
            $idx
        );

        return new JitVariable(
            $context,
            JitVariable::TYPE_VALUE,
            JitVariable::KIND_VARIABLE,
            $keyBox->value
        );
    }

    /** Read original leaf keys from RecursiveIteratorIterator `__spl_keys` (#27257). */
    private static function compileKeyRecursiveIteratorIterator(Context $context, JitVariable $slotKey): JitVariable
    {
        $receiver = JitVariable::TYPE_OBJECT === $slotKey->type
            ? $slotKey
            : VmIteratorProtocol::normalizeObjectReceiver($context, $slotKey);
        $keysHt = \PHPCompiler\JIT\Call\RecursiveIteratorIteratorConstruct::keysHashtable($context, $receiver);
        $idx = $context->builder->load(self::indexSlot($context, $slotKey));
        $keyBox = HashTableHelper::readIndexedToValueBox(
            $context,
            $context->helper->loadValue($keysHt),
            $idx
        );

        return new JitVariable(
            $context,
            JitVariable::TYPE_VALUE,
            JitVariable::KIND_VARIABLE,
            $keyBox->value
        );
    }

    /**
     * SplObjectStorage foreach key — insertion index, not the object (#28707).
     * php-src: spl_object_storage_get_current_key
     */
    private static function compileKeyObject(Context $context, JitVariable $slotKey): JitVariable
    {
        $slot = JitValueBox::alloc($context);
        $destPtr = JitValueBox::pointer($context, $slot);
        $idx = $context->builder->load(self::indexSlot($context, $slotKey));
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $destPtr,
            $context->builder->truncOrBitCast($idx, $context->getTypeFromString('int64'))
        );

        return new JitVariable($context, JitVariable::TYPE_VALUE, JitVariable::KIND_VARIABLE, $slot);
    }

    private static function compileKeyHashtable(Context $context, JitVariable $array, JitVariable $slotKey): JitVariable
    {
        $slot = JitValueBox::alloc($context);
        $destPtr = JitValueBox::pointer($context, $slot);
        $ht = $context->helper->loadValue($array);
        $map = $context->structFieldMap['__hashtable__'];
        $fn = $context->builder->getInsertBlock()->getParent();
        $legacy = $fn->appendBasicBlock('foreach_key_mode_legacy');
        $insertion = $fn->appendBasicBlock('foreach_key_mode_insertion');
        $done = $fn->appendBasicBlock('foreach_key_mode_done');
        $context->builder->branchIf(self::usesInsertionOrderForeach($context, $ht, $map), $insertion, $legacy);

        $context->builder->positionAtEnd($legacy);
        self::compileKeyHashtableLegacyBody($context, $array, $slotKey, $destPtr);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($insertion);
        self::compileKeyHashtableInsertionBody($context, $array, $slotKey, $destPtr);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);

        return new JitVariable($context, JitVariable::TYPE_VALUE, JitVariable::KIND_VARIABLE, $slot);
    }

    private static function compileKeyHashtableLegacyBody(
        Context $context,
        JitVariable $array,
        JitVariable $slotKey,
        \PHPLLVM\Value $destPtr
    ): void {
        $ht = $context->helper->loadValue($array);
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $idx = $context->builder->load(self::indexSlot($context, $slotKey));
        $nextFree = $context->builder->load($context->builder->structGep($ht, $map['nextFreeElement']));
        $inPacked = self::icmpUltSizeT($context, $idx, $nextFree);
        $fn = $context->builder->getInsertBlock()->getParent();
        $packed = $fn->appendBasicBlock('foreach_key_packed');
        $str = $fn->appendBasicBlock('foreach_key_str');
        $done = $fn->appendBasicBlock('foreach_key_done');
        $context->builder->branchIf($inPacked, $packed, $str);
        $context->builder->positionAtEnd($packed);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $destPtr,
            $context->builder->truncOrBitCast($idx, $context->getTypeFromString('int64'))
        );
        $context->builder->branch($done);
        $context->builder->positionAtEnd($str);
        $node = self::stringKeyNodeAt($context, $ht, $map, $nodeMap, $slotKey);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $ownedKey = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $keyStr
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $destPtr,
            $ownedKey
        );
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
    }

    private static function compileKeyHashtableInsertionBody(
        Context $context,
        JitVariable $array,
        JitVariable $slotKey,
        \PHPLLVM\Value $destPtr
    ): void {
        $ht = $context->helper->loadValue($array);
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $sizeT = $context->getTypeFromString('size_t');
        $linearPos = $context->builder->load(self::indexSlot($context, $slotKey));
        $prefixEnd = $context->builder->load($context->builder->structGep($ht, $map['packedPrefixEnd']));
        $strCount = self::foreachStringKeyCount($context, $ht, $map);
        $strEnd = $context->builder->addNoSignedWrap($prefixEnd, $strCount);
        $inPrefix = self::icmpUltSizeT($context, $linearPos, $prefixEnd);
        $inStr = self::icmpUltSizeT($context, $linearPos, $strEnd);
        $fn = $context->builder->getInsertBlock()->getParent();
        $prefix = $fn->appendBasicBlock('foreach_key_ins_prefix');
        $str = $fn->appendBasicBlock('foreach_key_ins_str');
        $late = $fn->appendBasicBlock('foreach_key_ins_late');
        $done = $fn->appendBasicBlock('foreach_key_ins_done');
        $routeLate = $fn->appendBasicBlock('foreach_key_ins_route_late');
        $context->builder->branchIf($inPrefix, $prefix, $routeLate);
        $context->builder->positionAtEnd($routeLate);
        $context->builder->branchIf($inStr, $str, $late);
        $context->builder->positionAtEnd($prefix);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $destPtr,
            $context->builder->truncOrBitCast($linearPos, $context->getTypeFromString('int64'))
        );
        $context->builder->branch($done);
        $context->builder->positionAtEnd($late);
        $lateOffset = $context->builder->sub(
            $context->builder->sub($linearPos, $prefixEnd),
            $strCount
        );
        $lateIdx = $context->builder->addNoSignedWrap($prefixEnd, $lateOffset);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $destPtr,
            $context->builder->truncOrBitCast($lateIdx, $context->getTypeFromString('int64'))
        );
        $context->builder->branch($done);
        $context->builder->positionAtEnd($str);
        $ord = $context->builder->sub($linearPos, $prefixEnd);
        $node = self::stringKeyNodeAtOrdinal($context, $ht, $map, $nodeMap, $ord);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $ownedKey = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $keyStr
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $destPtr,
            $ownedKey
        );
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
    }

    private static function compileKeyWeakMap(Context $context, JitVariable $array, JitVariable $slotKey): JitVariable
    {
        WeakRefRuntime::ensureLinked($context);
        WeakRefNative::registerDeclarations($context);

        $ht = $context->helper->loadValue($array);
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $node = self::stringKeyNodeAt($context, $ht, $map, $nodeMap, $slotKey);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $obj = $context->builder->call(
            $context->lookupFunction('phpc_weakref_map_key_to_object'),
            $keyStr
        );
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $slot),
            $obj
        );

        return new JitVariable($context, JitVariable::TYPE_VALUE, JitVariable::KIND_VARIABLE, $slot);
    }

    public static function compileValue(
        Context $context,
        JitVariable $array,
        ?string $containerUserType = null
    ): JitVariable {
        $slotKey = $array;
        if (isset($context->foreachDomNodeListSlots[$context->foreachSlotMapKey($slotKey)])) {
            $snapshotHt = $context->foreachDomNodeListSlots[$context->foreachSlotMapKey($slotKey)];

            return self::compileValueHashtable($context, $snapshotHt, $slotKey);
        }
        if (isset($context->foreachDatePeriodSnapshotHts[$context->foreachSlotMapKey($slotKey)])) {
            $ht = DatePeriodForeachSnapshot::hashtableFor($context, $slotKey);
            $boxed = self::compileValueHashtable($context, $ht, $slotKey);
            $mapKey = $context->foreachSlotMapKey($slotKey);
            // SXE snapshots bake name/text on TYPE_OBJECT; foreach HT load is TYPE_VALUE and
            // (string) cast skipped baked folds → SIGSEGV (#34543 / re-#27535).
            if (isset($context->foreachSimpleXmlSnapshotKeys[$mapKey])) {
                $valuePtr = JitValueBox::valuePtrFromVariable($context, $boxed);
                $obj = $context->builder->call(
                    $context->lookupFunction('__value__readObject'),
                    $valuePtr
                );

                return new JitVariable(
                    $context,
                    JitVariable::TYPE_OBJECT,
                    JitVariable::KIND_VALUE,
                    $obj
                );
            }

            return $boxed;
        }
        if (ObjectPropertyForeachHelper::canLower($context, $array, $containerUserType)) {
            return ObjectPropertyForeachHelper::compileValue($context, $slotKey, $containerUserType);
        }
        if (isset($context->foreachAggregateGeneratorResume[$context->foreachSlotMapKey($slotKey)])) {
            return \PHPCompiler\JIT\GeneratorHelper::compileIterValue(
                $context,
                self::loadAggregateGeneratorReceiver($context, $slotKey)
            );
        }
        if (isset($context->foreachAggregateInnerHtSlots[$context->foreachSlotMapKey($slotKey)])) {
            return self::compileValueHashtable(
                $context,
                self::hashtableFromAggregateInner($context, $slotKey),
                $slotKey
            );
        }
        if (IteratorProtocolHelper::canLowerIteratorProtocol($context, $array, $containerUserType)) {
            return IteratorProtocolHelper::compileForeachValue($context, $slotKey, $containerUserType);
        }
        $array = self::asHashtable($context, $array, $containerUserType);
        if (self::usesObjectKeys($containerUserType)) {
            return self::compileValueObject($context, $slotKey);
        }

        return self::compileValueHashtable($context, $array, $slotKey);
    }

    public static function compileValueByRef(
        Context $context,
        JitVariable $array,
        ?string $containerUserType = null,
        ?JIT $jit = null
    ): JitVariable {
        $slotKey = $array;
        if (ObjectPropertyForeachHelper::canLower($context, $array, $containerUserType)) {
            return ObjectPropertyForeachHelper::compileValueByRef($context, $slotKey, $containerUserType);
        }
        if (isset($context->foreachAggregateGeneratorResume[$context->foreachSlotMapKey($slotKey)])) {
            return \PHPCompiler\JIT\GeneratorHelper::compileIterValueByRef(
                $context,
                self::loadAggregateGeneratorReceiver($context, $slotKey),
                $jit
            );
        }
        if (isset($context->foreachAggregateInnerHtSlots[$context->foreachSlotMapKey($slotKey)])) {
            // Inner ArrayIterator `__spl_ht` — same FE_RESET_RW allow-list as ArrayIterator (#19444, #26785).
            return self::compileValueByRefHashtable(
                $context,
                self::hashtableFromAggregateInner($context, $slotKey),
                $slotKey
            );
        }
        if (IteratorProtocolHelper::canLowerIteratorProtocol($context, $array, $containerUserType)) {
            // Zend FE_RESET_RW allow-list: ArrayIterator / ArrayObject / RecursiveArrayIterator (#19444).
            // Pure LLVM write-through needs a backing HT on the object; until then, fetch via
            // iterator current() (by-value). VM opcode path in lib/VM.php binds live storage.
            if (self::isArrayBackedSplIteratorUserType($containerUserType)) {
                return IteratorProtocolHelper::compileForeachValue($context, $slotKey, $containerUserType);
            }
            self::emitForeachIteratorByRefError($context, $jit);
            $slot = JitValueBox::alloc($context);

            return new JitVariable($context, JitVariable::TYPE_VALUE, JitVariable::KIND_VARIABLE, $slot);
        }
        $array = self::asHashtable($context, $array, $containerUserType);
        if (self::usesObjectKeys($containerUserType)) {
            return self::compileValueByRefObject($context, $slotKey);
        }

        return self::compileValueByRefHashtable($context, $array, $slotKey);
    }

    /**
     * php-src zend_execute.c FE_RESET_RW — array-backed SPL containers (#19444).
     */
    private static function isArrayBackedSplIteratorUserType(?string $containerUserType): bool
    {
        if (null === $containerUserType || '' === $containerUserType) {
            return false;
        }
        $lc = strtolower(ltrim($containerUserType, '\\'));

        return 'arrayiterator' === $lc
            || 'arrayobject' === $lc
            || 'recursivearrayiterator' === $lc;
    }

    private static function emitForeachIteratorByRefError(Context $context, ?JIT $jit): void
    {
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        if (null !== $jit && [] !== $context->tryCatch->handlerStack) {
            TryCatchHelper::emitCatchableErrorMessage($context, $jit, self::FOREACH_ITERATOR_BYREF_ERROR);

            return;
        }
        ErrorRaise::emitRaise($context, self::FOREACH_ITERATOR_BYREF_ERROR);
    }

    private static function compileValueByRefNativeArray(Context $context, JitVariable $array): JitVariable
    {
        $elemType = $array->type & ~JitVariable::IS_NATIVE_ARRAY;
        $idx = $context->builder->load(self::indexSlot($context, $array));
        $zero = $context->getTypeFromString('size_t')->constInt(0, false);
        $slot = $context->builder->inBoundsGep($array->value, $zero, $idx);

        $var = new JitVariable($context, $elemType, JitVariable::KIND_VARIABLE, $slot);
        $var->borrowedValueEntry = true;

        return $var;
    }

    /**
     * SplObjectStorage foreach value — the stored object key, not the info (#28707).
     * php-src: spl_object_storage_get_current_data; info via getInfo() / offsetGet.
     */
    private static function compileValueObject(Context $context, JitVariable $slotKey): JitVariable
    {
        $slot = JitValueBox::alloc($context);
        $destPtr = JitValueBox::pointer($context, $slot);
        $nodeMap = $context->structFieldMap['__objkey_node__'];
        $node = $context->builder->load(self::objNodeSlot($context, $slotKey));
        $keyObj = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $destPtr,
            $keyObj
        );

        return new JitVariable($context, JitVariable::TYPE_VALUE, JitVariable::KIND_VARIABLE, $slot);
    }

    private static function compileValueByRefObject(Context $context, JitVariable $slotKey): JitVariable
    {
        $nodeMap = $context->structFieldMap['__objkey_node__'];
        $node = $context->builder->load(self::objNodeSlot($context, $slotKey));
        $valField = $context->builder->structGep($node, $nodeMap['value']);

        $var = new JitVariable($context, JitVariable::TYPE_VALUE, JitVariable::KIND_VARIABLE, $valField);
        $var->borrowedValueEntry = true;

        return $var;
    }

    private static function compileValueByRefHashtable(Context $context, JitVariable $array, JitVariable $slotKey): JitVariable
    {
        if ($slotKey->type & JitVariable::IS_NATIVE_ARRAY) {
            return self::compileValueByRefNativeArray($context, $slotKey);
        }
        $ht = $context->helper->loadValue($array);
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $idx = $context->builder->load(self::indexSlot($context, $slotKey));
        $nextFree = $context->builder->load($context->builder->structGep($ht, $map['nextFreeElement']));
        $inPacked = self::icmpUltSizeT($context, $idx, $nextFree);
        $fn = $context->builder->getInsertBlock()->getParent();
        $packed = $fn->appendBasicBlock('foreach_valref_packed');
        $str = $fn->appendBasicBlock('foreach_valref_str');
        $done = $fn->appendBasicBlock('foreach_valref_done');
        $context->builder->branchIf($inPacked, $packed, $str);
        $context->builder->positionAtEnd($packed);
        $packedEntry = HashTableHelper::listEntryPointer($context, $ht, $idx);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($str);
        $node = self::stringKeyNodeAt($context, $ht, $map, $nodeMap, $slotKey);
        $strEntry = $context->builder->structGep($node, $nodeMap['value']);
        $strEntryBlock = $context->builder->getInsertBlock();
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
        $entry = $context->builder->phi($packedEntry->typeOf());
        $entry->addIncoming($packedEntry, $packed);
        $entry->addIncoming($strEntry, $strEntryBlock);
        $var = new JitVariable($context, JitVariable::TYPE_VALUE, JitVariable::KIND_VARIABLE, $entry);
        $var->borrowedValueEntry = true;
        $var->writableHt = $ht;
        $var->writableIndex = $idx;
        $i1 = $context->getTypeFromString('int1');
        $packedArm = $context->builder->phi($i1);
        $packedArm->addIncoming($i1->constInt(1, false), $packed);
        $packedArm->addIncoming($i1->constInt(0, false), $strEntryBlock);
        $var->foreachByRefPackedArm = $packedArm;

        return $var;
    }

    private static function compileValueHashtable(Context $context, JitVariable $array, JitVariable $slotKey): JitVariable
    {
        $slot = JitValueBox::alloc($context);
        $destPtr = JitValueBox::pointer($context, $slot);
        $ht = $context->helper->loadValue($array);
        $map = $context->structFieldMap['__hashtable__'];
        $fn = $context->builder->getInsertBlock()->getParent();
        $legacy = $fn->appendBasicBlock('foreach_val_mode_legacy');
        $insertion = $fn->appendBasicBlock('foreach_val_mode_insertion');
        $done = $fn->appendBasicBlock('foreach_val_mode_done');
        $context->builder->branchIf(self::usesInsertionOrderForeach($context, $ht, $map), $insertion, $legacy);

        $context->builder->positionAtEnd($legacy);
        self::compileValueHashtableLegacyBody($context, $array, $slotKey, $destPtr);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($insertion);
        self::compileValueHashtableInsertionBody($context, $array, $slotKey, $destPtr);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);

        return new JitVariable($context, JitVariable::TYPE_VALUE, JitVariable::KIND_VARIABLE, $slot);
    }

    private static function compileValueHashtableLegacyBody(
        Context $context,
        JitVariable $array,
        JitVariable $slotKey,
        \PHPLLVM\Value $destPtr
    ): void {
        $ht = $context->helper->loadValue($array);
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $valueMap = $context->structFieldMap['__value__'];
        $idx = $context->builder->load(self::indexSlot($context, $slotKey));
        $nextFree = $context->builder->load($context->builder->structGep($ht, $map['nextFreeElement']));
        $inPacked = self::icmpUltSizeT($context, $idx, $nextFree);
        $fn = $context->builder->getInsertBlock()->getParent();
        $packed = $fn->appendBasicBlock('foreach_val_packed');
        $str = $fn->appendBasicBlock('foreach_val_str');
        $done = $fn->appendBasicBlock('foreach_val_done');
        $context->builder->branchIf($inPacked, $packed, $str);
        $context->builder->positionAtEnd($packed);
        $values = $context->builder->load($context->builder->structGep($ht, $map['values']));
        $entry = $context->builder->inBoundsGep($values, $idx);
        self::copyValueEntryToBox($context, $destPtr, $entry, $valueMap, $fn);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($str);
        $node = self::stringKeyNodeAt($context, $ht, $map, $nodeMap, $slotKey);
        $valField = $context->builder->structGep($node, $nodeMap['value']);
        self::copyValueEntryToBox($context, $destPtr, $valField, $valueMap, $fn);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
    }

    private static function compileValueHashtableInsertionBody(
        Context $context,
        JitVariable $array,
        JitVariable $slotKey,
        \PHPLLVM\Value $destPtr
    ): void {
        $ht = $context->helper->loadValue($array);
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $valueMap = $context->structFieldMap['__value__'];
        $linearPos = $context->builder->load(self::indexSlot($context, $slotKey));
        $prefixEnd = $context->builder->load($context->builder->structGep($ht, $map['packedPrefixEnd']));
        $strCount = self::foreachStringKeyCount($context, $ht, $map);
        $strEnd = $context->builder->addNoSignedWrap($prefixEnd, $strCount);
        $inPrefix = self::icmpUltSizeT($context, $linearPos, $prefixEnd);
        $inStr = self::icmpUltSizeT($context, $linearPos, $strEnd);
        $fn = $context->builder->getInsertBlock()->getParent();
        $prefix = $fn->appendBasicBlock('foreach_val_ins_prefix');
        $str = $fn->appendBasicBlock('foreach_val_ins_str');
        $late = $fn->appendBasicBlock('foreach_val_ins_late');
        $done = $fn->appendBasicBlock('foreach_val_ins_done');
        $routeLate = $fn->appendBasicBlock('foreach_val_ins_route_late');
        $context->builder->branchIf($inPrefix, $prefix, $routeLate);
        $context->builder->positionAtEnd($routeLate);
        $context->builder->branchIf($inStr, $str, $late);
        $context->builder->positionAtEnd($prefix);
        $entry = HashTableHelper::listEntryPointer($context, $ht, $linearPos);
        self::copyValueEntryToBox($context, $destPtr, $entry, $valueMap, $fn);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($late);
        $lateOffset = $context->builder->sub(
            $context->builder->sub($linearPos, $prefixEnd),
            $strCount
        );
        $lateIdx = $context->builder->addNoSignedWrap($prefixEnd, $lateOffset);
        $lateEntry = HashTableHelper::listEntryPointer($context, $ht, $lateIdx);
        self::copyValueEntryToBox($context, $destPtr, $lateEntry, $valueMap, $fn);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($str);
        $ord = $context->builder->sub($linearPos, $prefixEnd);
        $node = self::stringKeyNodeAtOrdinal($context, $ht, $map, $nodeMap, $ord);
        $valField = $context->builder->structGep($node, $nodeMap['value']);
        self::copyValueEntryToBox($context, $destPtr, $valField, $valueMap, $fn);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
    }

    /**
     * @param array<string, int> $map
     * @param array<string, int> $nodeMap
     */
    private static function stringKeyNodeAt(
        Context $context,
        \PHPLLVM\Value $ht,
        array $map,
        array $nodeMap,
        JitVariable $slotKey
    ): \PHPLLVM\Value {
        $idx = $context->builder->load(self::indexSlot($context, $slotKey));
        $nextFree = $context->builder->load($context->builder->structGep($ht, $map['nextFreeElement']));
        $ord = $context->builder->sub($idx, $nextFree);

        return self::stringKeyNodeAtOrdinal($context, $ht, $map, $nodeMap, $ord);
    }

    /**
     * @param array<string, int> $map
     * @param array<string, int> $nodeMap
     */
    private static function stringKeyNodeAtOrdinal(
        Context $context,
        \PHPLLVM\Value $ht,
        array $map,
        array $nodeMap,
        \PHPLLVM\Value $ord
    ): \PHPLLVM\Value {
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $head = $context->builder->load($context->builder->structGep($ht, $map['strKeys']));
        $block = $context->builder->getInsertBlock();
        $fn = $block->getParent();
        $walkHead = $fn->appendBasicBlock('foreach_node_head');
        $walkBody = $fn->appendBasicBlock('foreach_node_body');
        $walkDone = $fn->appendBasicBlock('foreach_node_done');
        $context->builder->branch($walkHead);
        $context->builder->positionAtEnd($walkHead);
        $node = $context->builder->phi($head->typeOf());
        $node->addIncoming($head, $block);
        $remaining = $context->builder->phi($sizeT);
        $remaining->addIncoming($ord, $block);
        $atTarget = $context->builder->icmp(Builder::INT_EQ, $remaining, $zero);
        $context->builder->branchIf($atTarget, $walkDone, $walkBody);
        $context->builder->positionAtEnd($walkBody);
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $node->addIncoming($nextNode, $walkBody);
        $remaining->addIncoming($context->builder->sub($remaining, $one), $walkBody);
        $context->builder->branch($walkHead);
        $context->builder->positionAtEnd($walkDone);

        return $node;
    }

    /**
     * @param array<string, int> $valueMap
     */
    private static function copyValueEntryToBox(
        Context $context,
        \PHPLLVM\Value $destPtr,
        \PHPLLVM\Value $entry,
        array $valueMap,
        \PHPLLVM\LLVMAbstract\Value\Function_ $fn
    ): void {
        // Full __value__ copy (hashtable / double / bool / null / …). The previous
        // string|object|long switch coerced nested arrays to long 0 — nested foreach
        // and `$row[0]` after `foreach ($g as $row)` were wrong (#24010).
        unset($valueMap, $fn);
        JitValueBox::copyIntoPointer($context, $destPtr, $entry);
    }
}

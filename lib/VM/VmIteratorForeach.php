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

        return true;
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
            $valPtr = JitValueBox::valuePtrFromVariable($context, $array);
            $ht = $context->builder->call(
                $context->lookupFunction('__value__readHashtable'),
                $valPtr
            );

            return new JitVariable(
                $context,
                JitVariable::TYPE_HASHTABLE,
                JitVariable::KIND_VALUE,
                $ht
            );
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
        if (ObjectPropertyForeachHelper::canLower($context, $array, $containerUserType)) {
            ObjectPropertyForeachHelper::compileReset($context, $array, $slotKey);

            return;
        }
        // Before Iterator protocol — DatePeriod rewind/clone is AOT-unsafe (#26772).
        if (DatePeriodForeachSnapshot::canLower($array)) {
            DatePeriodForeachSnapshot::compileReset($context, $array, $slotKey);

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
        $array = self::asHashtable($context, $array, $containerUserType);
        if (self::usesObjectKeys($containerUserType)) {
            $nodePtrType = $context->getTypeFromString('__objkey_node__*');
            $context->builder->store(
                $nodePtrType->constNull(),
                self::objNodeSlot($context, $slotKey)
            );

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
        if (ObjectPropertyForeachHelper::canLower($context, $array, $containerUserType)) {
            return ObjectPropertyForeachHelper::compileValid($context, $slotKey, $containerUserType);
        }
        if (isset($context->foreachDatePeriodSnapshotHts[$context->foreachSlotMapKey($slotKey)])) {
            $ht = DatePeriodForeachSnapshot::hashtableFor($context, $slotKey);

            return self::compileValidHashtable($context, $ht, $slotKey);
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

        $context->builder->positionAtEnd($init);
        $head = $context->builder->load($context->builder->structGep($ht, $map['objKeys']));
        $context->builder->store($head, $walkSlot);
        $context->builder->branch($check);

        $context->builder->positionAtEnd($advance);
        $next = $context->builder->load($context->builder->structGep($current, $nodeMap['next']));
        $context->builder->store($next, $walkSlot);
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
            $i8->constInt(Variable::TYPE_UNDEFINED, false)
        );
        $context->builder->branchIf($isDefined, $found, $packedHead);

        $context->builder->positionAtEnd($strInit);
        $strEntry = $fn->appendBasicBlock('foreach_str_entry');
        $context->builder->branch($strEntry);

        $context->builder->positionAtEnd($strEntry);
        $ord = $context->builder->load($slot);
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

    public static function compileKey(
        Context $context,
        JitVariable $array,
        ?string $containerUserType = null
    ): JitVariable {
        $slotKey = $array;
        if (ObjectPropertyForeachHelper::canLower($context, $array, $containerUserType)) {
            return ObjectPropertyForeachHelper::compileKey($context, $slotKey, $containerUserType);
        }
        if (isset($context->foreachDatePeriodSnapshotHts[$context->foreachSlotMapKey($slotKey)])) {
            $ht = DatePeriodForeachSnapshot::hashtableFor($context, $slotKey);

            return self::compileKeyHashtable($context, $ht, $slotKey);
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

    private static function compileKeyObject(Context $context, JitVariable $slotKey): JitVariable
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

    private static function compileKeyHashtable(Context $context, JitVariable $array, JitVariable $slotKey): JitVariable
    {
        $slot = JitValueBox::alloc($context);
        $destPtr = JitValueBox::pointer($context, $slot);
        $ht = $context->helper->loadValue($array);
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $sizeT = $context->getTypeFromString('size_t');
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
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $destPtr,
            $keyStr
        );
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);

        return new JitVariable($context, JitVariable::TYPE_VALUE, JitVariable::KIND_VARIABLE, $slot);
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
        if (ObjectPropertyForeachHelper::canLower($context, $array, $containerUserType)) {
            return ObjectPropertyForeachHelper::compileValue($context, $slotKey, $containerUserType);
        }
        if (isset($context->foreachDatePeriodSnapshotHts[$context->foreachSlotMapKey($slotKey)])) {
            $ht = DatePeriodForeachSnapshot::hashtableFor($context, $slotKey);

            return self::compileValueHashtable($context, $ht, $slotKey);
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

    private static function compileValueObject(Context $context, JitVariable $slotKey): JitVariable
    {
        $slot = JitValueBox::alloc($context);
        $destPtr = JitValueBox::pointer($context, $slot);
        $nodeMap = $context->structFieldMap['__objkey_node__'];
        $valueMap = $context->structFieldMap['__value__'];
        $node = $context->builder->load(self::objNodeSlot($context, $slotKey));
        $valField = $context->builder->structGep($node, $nodeMap['value']);
        $fn = $context->builder->getInsertBlock()->getParent();
        self::copyValueEntryToBox($context, $destPtr, $valField, $valueMap, $fn);

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

        return new JitVariable($context, JitVariable::TYPE_VALUE, JitVariable::KIND_VARIABLE, $slot);
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
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $ord = $context->builder->load(self::indexSlot($context, $slotKey));
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

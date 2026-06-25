<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TypedPropertyUninitGuard;
use PHPCompiler\JIT\Variable as JitVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * SSOT for JIT foreach over user object / stdClass instance properties (#3661, #5034, #10239).
 *
 * Used when Iterator protocol does not apply. php-src: Zend/zend_execute.c — ZEND_FE_FETCH_R object branch.
 */
final class VmObjectPropertyForeach
{
    public static function canLower(Context $context, JitVariable $container, ?string $containerUserType): bool
    {
        if ($container->type & JitVariable::IS_NATIVE_ARRAY) {
            return false;
        }
        if (JitVariable::TYPE_HASHTABLE === $container->type) {
            return false;
        }
        if (
            JitVariable::TYPE_OBJECT !== $container->type
            && JitVariable::TYPE_VALUE !== $container->type
        ) {
            return false;
        }
        // Ambiguous boxed containers without a declared object class use hashtable foreach (#1492).
        if (JitVariable::TYPE_VALUE === $container->type && (null === $containerUserType || '' === $containerUserType)) {
            return false;
        }
        if (null !== $containerUserType && '' !== $containerUserType) {
            $classLc = strtolower(ltrim($containerUserType, '\\'));
            if ('object' !== $classLc) {
                return !VmIteratorProtocol::classImplementsIteratorProtocol($context, $classLc);
            }
        }

        return !VmIteratorProtocol::canLowerIteratorProtocol($context, $container, $containerUserType);
    }

    public static function compileReset(Context $context, JitVariable $container, JitVariable $slotKey): void
    {
        $receiver = VmIteratorProtocol::normalizeObjectReceiver($context, $container);
        VmIteratorProtocol::storeReceiver($context, $slotKey, $receiver);
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $invalid = $context->builder->sub($zero, $one);
        $context->builder->store($invalid, self::indexSlot($context, $slotKey));
    }

    public static function compileValid(
        Context $context,
        JitVariable $slotKey,
        ?string $containerUserType = null
    ): Value {
        $sizeT = $context->getTypeFromString('size_t');
        $slot = self::indexSlot($context, $slotKey);
        $idx = $context->builder->load($slot);
        $nextIdx = $context->builder->addNoSignedWrap($idx, $sizeT->constInt(1, false));
        $context->builder->store($nextIdx, $slot);

        if (null !== $containerUserType && '' !== $containerUserType) {
            $classId = $context->type->object->lookup(strtolower(ltrim($containerUserType, '\\')));
            $count = \count($context->type->object->instancePropertySets($classId));

            return $context->builder->icmp(
                Builder::INT_ULT,
                $nextIdx,
                $sizeT->constInt($count, false)
            );
        }

        $receiver = VmIteratorProtocol::loadReceiver($context, $slotKey);
        $obj = $context->helper->loadValue($receiver);
        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load($context->builder->structGep($obj, $objMap['class_id']));

        return self::validForRuntimeClass($context, $nextIdx, $classId);
    }

    public static function compileKey(
        Context $context,
        JitVariable $slotKey,
        ?string $containerUserType = null
    ): JitVariable {
        $slot = JitValueBox::alloc($context);
        $destPtr = JitValueBox::pointer($context, $slot);
        $idx = $context->builder->load(self::indexSlot($context, $slotKey));
        self::emitPropertyNameAtIndex($context, $slotKey, $containerUserType, $idx, $destPtr);

        return new JitVariable($context, JitVariable::TYPE_VALUE, JitVariable::KIND_VARIABLE, $slot);
    }

    public static function compileValue(
        Context $context,
        JitVariable $slotKey,
        ?string $containerUserType = null
    ): JitVariable {
        $fetched = self::propertyAtIndex($context, $slotKey, $containerUserType);
        $slot = JitValueBox::alloc($context);
        $context->type->object->boxFetchedPropertyIntoValueBox($slot, $fetched);

        return new JitVariable($context, JitVariable::TYPE_VALUE, JitVariable::KIND_VARIABLE, $slot);
    }

    public static function compileValueByRef(
        Context $context,
        JitVariable $slotKey,
        ?string $containerUserType = null
    ): JitVariable {
        $fetched = self::propertyAtIndex($context, $slotKey, $containerUserType);
        if (null === $fetched->objectPropertySlot) {
            throw new \LogicException('foreach object by-ref requires a property lvalue slot');
        }
        $fetched->borrowedValueEntry = true;

        return $fetched;
    }

    private static function validForRuntimeClass(Context $context, Value $nextIdx, Value $classId): Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $fn = $context->builder->getInsertBlock()->getParent();
        $merge = $fn->appendBasicBlock('foreach_objprop_valid_done');
        $result = $context->builder->phi($i1);
        $entry = $context->builder->getInsertBlock();
        $checkBlock = $entry;
        foreach ($context->type->object->allClassNamesById() as $id => $_) {
            $props = $context->type->object->instancePropertySets($id);
            if ($checkBlock !== $entry) {
                $context->builder->positionAtEnd($checkBlock);
            }
            $isClass = $context->builder->icmp(
                Builder::INT_EQ,
                $classId,
                $context->constantFromInteger($id, 'int64')
            );
            $matchBlock = $fn->appendBasicBlock('foreach_objprop_valid_class_'.$id);
            $nextCheck = $fn->appendBasicBlock('foreach_objprop_valid_after_'.$id);
            $context->builder->branchIf($isClass, $matchBlock, $nextCheck);
            $context->builder->positionAtEnd($matchBlock);
            $valid = $context->builder->icmp(
                Builder::INT_ULT,
                $nextIdx,
                $sizeT->constInt(\count($props), false)
            );
            $context->builder->branch($merge);
            $result->addIncoming($valid, $matchBlock);
            $checkBlock = $nextCheck;
        }
        $context->builder->positionAtEnd($checkBlock);
        $context->builder->branch($merge);
        $result->addIncoming($i1->constInt(0, false), $checkBlock);
        $context->builder->positionAtEnd($merge);

        return $result;
    }

    private static function propertyAtIndex(
        Context $context,
        JitVariable $slotKey,
        ?string $containerUserType
    ): JitVariable {
        $idx = $context->builder->load(self::indexSlot($context, $slotKey));
        $receiver = VmIteratorProtocol::loadReceiver($context, $slotKey);
        $obj = $context->helper->loadValue($receiver);

        if (null !== $containerUserType && '' !== $containerUserType) {
            return self::fetchPropertyForClassAtIndex($context, $obj, $containerUserType, $idx);
        }

        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load($context->builder->structGep($obj, $objMap['class_id']));

        return self::fetchPropertyForRuntimeClass($context, $obj, $classId, $idx);
    }

    private static function fetchPropertyForRuntimeClass(
        Context $context,
        Value $obj,
        Value $classId,
        Value $idx
    ): JitVariable {
        $fn = $context->builder->getInsertBlock()->getParent();
        $entry = $context->builder->getInsertBlock();
        $checkBlock = $entry;
        foreach ($context->type->object->allClassNamesById() as $id => $className) {
            if ($checkBlock !== $entry) {
                $context->builder->positionAtEnd($checkBlock);
            }
            $isClass = $context->builder->icmp(
                Builder::INT_EQ,
                $classId,
                $context->constantFromInteger($id, 'int64')
            );
            $matchBlock = $fn->appendBasicBlock('foreach_objprop_fetch_'.$id);
            $nextCheck = $fn->appendBasicBlock('foreach_objprop_fetch_next_'.$id);
            $context->builder->branchIf($isClass, $matchBlock, $nextCheck);
            $context->builder->positionAtEnd($matchBlock);

            return self::fetchPropertyForClassAtIndex($context, $obj, $className, $idx);
        }
        $context->builder->positionAtEnd($checkBlock);
        $context->builder->call($context->lookupFunction('abort'));

        throw new \LogicException('foreach object property fetch requires a known class');
    }

    private static function fetchPropertyForClassAtIndex(
        Context $context,
        Value $obj,
        string $className,
        Value $idx
    ): JitVariable {
        $classId = $context->type->object->lookup(strtolower(ltrim($className, '\\')));
        $props = $context->type->object->instancePropertySets($classId);
        $fn = $context->builder->getInsertBlock()->getParent();
        $done = $fn->appendBasicBlock('foreach_objprop_prop_done');
        $objectType = $context->type->object;
        $entry = $context->builder->getInsertBlock();
        $checkBlock = $entry;
        $fetched = null;
        foreach ($props as $i => $propset) {
            if ($checkBlock !== $entry) {
                $context->builder->positionAtEnd($checkBlock);
            }
            $atIdx = $context->builder->icmp(
                Builder::INT_EQ,
                $idx,
                $context->constantFromInteger($i, 'size_t')
            );
            $caseBlock = $fn->appendBasicBlock('foreach_objprop_prop_'.$classId.'_'.$i);
            $nextCheck = $i + 1 < \count($props)
                ? $fn->appendBasicBlock('foreach_objprop_prop_try_'.$classId.'_'.($i + 1))
                : $fn->appendBasicBlock('foreach_objprop_prop_oob_'.$classId);
            $context->builder->branchIf($atIdx, $caseBlock, $nextCheck);
            $context->builder->positionAtEnd($caseBlock);
            $fetched = $objectType->propertyFetch($obj, $className, $propset[1]);
            TypedPropertyUninitGuard::emitBeforeRead($context, $fetched);
            $context->builder->branch($done);
            $checkBlock = $nextCheck;
        }
        if (null === $fetched) {
            throw new \LogicException('foreach object property fetch requires at least one instance property');
        }
        $context->builder->positionAtEnd($checkBlock);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->positionAtEnd($done);

        return $fetched;
    }

    private static function emitPropertyNameAtIndex(
        Context $context,
        JitVariable $slotKey,
        ?string $containerUserType,
        Value $idx,
        Value $destPtr
    ): void {
        if (null !== $containerUserType && '' !== $containerUserType) {
            self::writePropertyNameForClassAtIndex($context, $containerUserType, $idx, $destPtr);

            return;
        }
        $receiver = VmIteratorProtocol::loadReceiver($context, $slotKey);
        $obj = $context->helper->loadValue($receiver);
        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load($context->builder->structGep($obj, $objMap['class_id']));
        $fn = $context->builder->getInsertBlock()->getParent();
        $entry = $context->builder->getInsertBlock();
        $checkBlock = $entry;
        foreach ($context->type->object->allClassNamesById() as $id => $className) {
            if ($checkBlock !== $entry) {
                $context->builder->positionAtEnd($checkBlock);
            }
            $isClass = $context->builder->icmp(
                Builder::INT_EQ,
                $classId,
                $context->constantFromInteger($id, 'int64')
            );
            $matchBlock = $fn->appendBasicBlock('foreach_objprop_key_'.$id);
            $nextCheck = $fn->appendBasicBlock('foreach_objprop_key_next_'.$id);
            $context->builder->branchIf($isClass, $matchBlock, $nextCheck);
            $context->builder->positionAtEnd($matchBlock);
            self::writePropertyNameForClassAtIndex($context, $className, $idx, $destPtr);

            return;
        }
        $context->builder->positionAtEnd($checkBlock);
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function writePropertyNameForClassAtIndex(
        Context $context,
        string $className,
        Value $idx,
        Value $destPtr
    ): void {
        $classId = $context->type->object->lookup(strtolower(ltrim($className, '\\')));
        $props = $context->type->object->instancePropertySets($classId);
        $fn = $context->builder->getInsertBlock()->getParent();
        $entry = $context->builder->getInsertBlock();
        $checkBlock = $entry;
        foreach ($props as $i => $propset) {
            if ($checkBlock !== $entry) {
                $context->builder->positionAtEnd($checkBlock);
            }
            $atIdx = $context->builder->icmp(
                Builder::INT_EQ,
                $idx,
                $context->constantFromInteger($i, 'size_t')
            );
            $caseBlock = $fn->appendBasicBlock('foreach_objprop_keyname_'.$classId.'_'.$i);
            $nextCheck = $i + 1 < \count($props)
                ? $fn->appendBasicBlock('foreach_objprop_keyname_try_'.$classId.'_'.($i + 1))
                : $fn->appendBasicBlock('foreach_objprop_keyname_oob_'.$classId);
            $context->builder->branchIf($atIdx, $caseBlock, $nextCheck);
            $context->builder->positionAtEnd($caseBlock);
            $keyStr = $context->builder->load($context->constantStringFromString($propset[1]));
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                $destPtr,
                $keyStr
            );

            return;
        }
        $context->builder->positionAtEnd($checkBlock);
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function indexSlot(Context $context, JitVariable $slotKey): Value
    {
        $key = \spl_object_id($slotKey);
        if (isset($context->foreachIndexSlots[$key])) {
            return $context->foreachIndexSlots[$key];
        }
        $sizeT = $context->getTypeFromString('size_t');
        $slot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->foreachIndexSlots[$key] = $slot;

        return $slot;
    }
}

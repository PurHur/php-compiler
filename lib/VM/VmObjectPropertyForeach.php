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
    /** Caller class for visibility (null = global / external scope) (#23430). */
    private static function foreachCallerScopeLc(Context $context): ?string
    {
        $name = $context->scope->className;
        if ('' === $name) {
            return null;
        }

        return strtolower(ltrim($name, '\\'));
    }

    /**
     * @return list<array{0: int, 1: string, 2: int, 3: int}>
     */
    private static function visiblePropertySets(Context $context, int $classId): array
    {
        return $context->type->object->instancePropertySetsVisibleFromScope(
            $classId,
            self::foreachCallerScopeLc($context)
        );
    }

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
        // Ambiguous boxed containers without a concrete object class use hashtable foreach
        // (#1492). Generic userType `object` (mixed / wide unions tagged by callResultCfgWantsObject)
        // must not claim property foreach either — function-returned arrays then iterate empty (#36469).
        if (JitVariable::TYPE_VALUE === $container->type) {
            if (null === $containerUserType || '' === $containerUserType) {
                return false;
            }
            if ('object' === strtolower(ltrim($containerUserType, '\\'))) {
                return false;
            }
        }
        if (null !== $containerUserType && '' !== $containerUserType) {
            $classLc = strtolower(ltrim($containerUserType, '\\'));
            // DatePeriod foreach is the date snapshot, not public start/end/interval (#33744).
            if ('dateperiod' === $classLc) {
                return false;
            }
            // WeakMap: walk `__weak_map` HT (object keys), not the private property (#33860).
            if ('weakmap' === $classLc) {
                return false;
            }
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
            $count = \count(self::visiblePropertySets($context, $classId));

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
        // Box inside each property case before the merge — a post-merge
        // objectPropertySlot from one case does not dominate (#34464).
        $slot = JitValueBox::alloc($context);
        self::emitPropertyValueAtIndex($context, $slotKey, $containerUserType, $slot);

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
            $props = self::visiblePropertySets($context, $id);
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

    private static function emitPropertyValueAtIndex(
        Context $context,
        JitVariable $slotKey,
        ?string $containerUserType,
        Value $destSlot
    ): void {
        $idx = $context->builder->load(self::indexSlot($context, $slotKey));
        $receiver = VmIteratorProtocol::loadReceiver($context, $slotKey);
        $obj = $context->helper->loadValue($receiver);

        if (null !== $containerUserType && '' !== $containerUserType) {
            self::fetchPropertyForClassAtIndex($context, $obj, $containerUserType, $idx, $destSlot);

            return;
        }

        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load($context->builder->structGep($obj, $objMap['class_id']));
        self::fetchPropertyForRuntimeClass($context, $obj, $classId, $idx, $destSlot);
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
            return self::fetchPropertyForClassAtIndex($context, $obj, $containerUserType, $idx, null);
        }

        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load($context->builder->structGep($obj, $objMap['class_id']));

        return self::fetchPropertyForRuntimeClass($context, $obj, $classId, $idx, null);
    }

    /**
     * @return JitVariable|null null when $destSlot is set (value already boxed)
     */
    private static function fetchPropertyForRuntimeClass(
        Context $context,
        Value $obj,
        Value $classId,
        Value $idx,
        ?Value $destSlot
    ): ?JitVariable {
        $fn = $context->builder->getInsertBlock()->getParent();
        $done = $fn->appendBasicBlock('foreach_objprop_fetch_done');
        $entry = $context->builder->getInsertBlock();
        $checkBlock = $entry;
        $slotIncomings = [];
        $propType = JitVariable::TYPE_VALUE;
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
            $fetched = self::fetchPropertyForClassAtIndex($context, $obj, $className, $idx, $destSlot);
            if (null === $destSlot) {
                if (null === $fetched || null === $fetched->objectPropertySlot) {
                    throw new \LogicException('foreach object by-ref requires a property lvalue slot');
                }
                $slotIncomings[] = [$fetched->objectPropertySlot, $context->builder->getInsertBlock()];
                $propType = $fetched->objectPropertyType ?? $fetched->type;
            }
            $context->builder->branch($done);
            $checkBlock = $nextCheck;
        }
        $context->builder->positionAtEnd($checkBlock);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);

        if (null !== $destSlot) {
            return null;
        }
        if ([] === $slotIncomings) {
            throw new \LogicException('foreach object property fetch requires a known class');
        }
        $phi = $context->builder->phi($slotIncomings[0][0]->typeOf());
        foreach ($slotIncomings as [$slot, $block]) {
            $phi->addIncoming($slot, $block);
        }
        $phi->addIncoming($slotIncomings[0][0]->typeOf()->constNull(), $checkBlock);
        $var = new JitVariable($context, $propType, JitVariable::KIND_VALUE, $phi);
        $var->objectPropertySlot = $phi;
        $var->objectPropertyType = $propType;

        return $var;
    }

    /**
     * @return JitVariable|null null when $destSlot is set (value already boxed)
     */
    private static function fetchPropertyForClassAtIndex(
        Context $context,
        Value $obj,
        string $className,
        Value $idx,
        ?Value $destSlot
    ): ?JitVariable {
        $classId = $context->type->object->lookup(strtolower(ltrim($className, '\\')));
        $props = self::visiblePropertySets($context, $classId);
        // Empty visible set: compileValid() never yields; fetch is unreachable (#24247 / re-#23430).
        if ([] === $props) {
            $context->builder->call($context->lookupFunction('abort'));
            if (null !== $destSlot) {
                return null;
            }
            $slot = JitValueBox::alloc($context);

            return new JitVariable($context, JitVariable::TYPE_VALUE, JitVariable::KIND_VARIABLE, $slot);
        }
        $fn = $context->builder->getInsertBlock()->getParent();
        $done = $fn->appendBasicBlock('foreach_objprop_prop_done');
        $objectType = $context->type->object;
        $entry = $context->builder->getInsertBlock();
        $checkBlock = $entry;
        $slotIncomings = [];
        $propType = JitVariable::TYPE_VALUE;
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
            if (null !== $destSlot) {
                $objectType->boxFetchedPropertyIntoValueBox($destSlot, $fetched);
            } else {
                if (null === $fetched->objectPropertySlot) {
                    throw new \LogicException('foreach object by-ref requires a property lvalue slot');
                }
                // Guard may insert blocks — PHI incoming must be the block that branches to $done.
                $slotIncomings[] = [$fetched->objectPropertySlot, $context->builder->getInsertBlock()];
                $propType = $fetched->objectPropertyType ?? $fetched->type;
            }
            $context->builder->branch($done);
            $checkBlock = $nextCheck;
        }
        $context->builder->positionAtEnd($checkBlock);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);

        if (null !== $destSlot) {
            return null;
        }
        $phi = $context->builder->phi($slotIncomings[0][0]->typeOf());
        foreach ($slotIncomings as [$slot, $block]) {
            $phi->addIncoming($slot, $block);
        }
        // oob → done has no slot; use null so PHI is well-formed if abort ever returned
        $phi->addIncoming($slotIncomings[0][0]->typeOf()->constNull(), $checkBlock);
        $var = new JitVariable($context, $propType, JitVariable::KIND_VALUE, $phi);
        $var->objectPropertySlot = $phi;
        $var->objectPropertyType = $propType;

        return $var;
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
        $done = $fn->appendBasicBlock('foreach_objprop_key_done');
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
            $context->builder->branch($done);
            $checkBlock = $nextCheck;
        }
        $context->builder->positionAtEnd($checkBlock);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
    }

    private static function writePropertyNameForClassAtIndex(
        Context $context,
        string $className,
        Value $idx,
        Value $destPtr
    ): void {
        $classId = $context->type->object->lookup(strtolower(ltrim($className, '\\')));
        $props = self::visiblePropertySets($context, $classId);
        $fn = $context->builder->getInsertBlock()->getParent();
        $done = $fn->appendBasicBlock('foreach_objprop_keyname_done');
        $entry = $context->builder->getInsertBlock();
        $checkBlock = $entry;
        if ([] === $props) {
            $context->builder->call($context->lookupFunction('abort'));
            $context->builder->branch($done);
            $context->builder->positionAtEnd($done);

            return;
        }
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
            $context->builder->branch($done);
            $checkBlock = $nextCheck;
        }
        $context->builder->positionAtEnd($checkBlock);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
    }

    private static function indexSlot(Context $context, JitVariable $slotKey): Value
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
}

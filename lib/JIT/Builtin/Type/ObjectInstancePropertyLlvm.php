<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin\Type;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\TypedPropertyUninitGuard;
use PHPCompiler\JIT\Variable;
use PHPCompiler\MethodVisibility;
use PHPLLVM;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for ordinary instance property fetch/box (#9938).
 */
final class ObjectInstancePropertyLlvm
{
    public static function propertyFetchOrdinary(
        Object_ $object,
        Value $obj,
        string $class,
        string $name,
        int $classId,
        bool $forWrite = false,
        ?Variable $receiverVar = null
    ): Variable {
        $classLc = strtolower(str_replace('/', '\\', ltrim($class, '\\')));
        if (\PHPCompiler\ext\dom\JitDomNodeChildProperty::isDomNodeChildProperty($classLc, strtolower($name))) {
            return \PHPCompiler\ext\dom\JitDomNodeChildProperty::fetch(
                $object,
                $obj,
                $name,
                $classLc,
                $receiverVar
            );
        }
        if (\PHPCompiler\ext\dom\JitDomParentNodeProperty::isDomParentNodeProperty($classLc, strtolower($name))) {
            return \PHPCompiler\ext\dom\JitDomParentNodeProperty::fetch($object, $obj);
        }
        if (\PHPCompiler\ext\dom\JitDomNodeIsConnected::isDomNodeIsConnected($classLc, strtolower($name))) {
            return \PHPCompiler\ext\dom\JitDomNodeIsConnected::fetch($object, $obj);
        }
        if (\PHPCompiler\ext\dom\JitDomElementNavigationProperty::isElementNavigationProperty($classLc, strtolower($name))) {
            return \PHPCompiler\ext\dom\JitDomElementNavigationProperty::fetch($object, $obj, $name);
        }
        if (\PHPCompiler\ext\dom\JitDomElementTextContent::isDomElementTextContent($classLc, strtolower($name))) {
            return \PHPCompiler\ext\dom\JitDomElementTextContent::fetchNamed($object, $obj, $name, $receiverVar);
        }
        if (\PHPCompiler\ext\dom\JitDomNodeListLength::isDomNodeListLength($classLc, strtolower($name))) {
            return \PHPCompiler\ext\dom\JitDomNodeListLength::fetch($object, $obj);
        }
        if (\PHPCompiler\ext\dom\JitDomNamedNodeMap::isLength($classLc, strtolower($name))) {
            return \PHPCompiler\ext\dom\JitDomNamedNodeMap::fetchLength($object, $obj);
        }
        if (\PHPCompiler\ext\dom\JitDomNamedNodeMap::isAttributesProperty($classLc, strtolower($name))) {
            return \PHPCompiler\ext\dom\JitDomNamedNodeMap::fetchAttributes($object, $obj, $class);
        }
        if (\PHPCompiler\ext\dom\JitDomDocumentElement::isDomDocumentElement($classLc, strtolower($name))) {
            return \PHPCompiler\ext\dom\JitDomDocumentElement::fetch($object, $obj, $receiverVar);
        }
        if (\PHPCompiler\ext\dom\JitDomDocumentDoctype::isDomDocumentDoctype($classLc, strtolower($name))) {
            return \PHPCompiler\ext\dom\JitDomDocumentDoctype::fetch($object, $obj, $class);
        }
        // childNodes must use the DOMNode slot LiveSlots/loadXML write — fetching via
        // DOMElement defineProperty'd a second index past the allocation (#327xx).
        if (\PHPCompiler\ext\dom\JitDomChildNodesProperty::isDomChildNodesProperty($classLc, strtolower($name))) {
            return \PHPCompiler\ext\dom\JitDomChildNodesProperty::fetch($object, $obj);
        }
        if (!$forWrite) {
            $asProps = \PHPCompiler\VM\ArrayObjectJitHelper::tryPropertyFetchRead(
                $object,
                $obj,
                $class,
                $name
            );
            if (null !== $asProps) {
                return $asProps;
            }
        } else {
            $asPropsWrite = \PHPCompiler\VM\ArrayObjectJitHelper::tryPropertyFetchWrite(
                $object,
                $obj,
                $class,
                $name
            );
            if (null !== $asPropsWrite) {
                return $asPropsWrite;
            }
        }

        return self::propertyFetchDeclaredSlot($object, $obj, $class, $name, $classId, $forWrite);
    }

    /** Slot read without ext/dom live-bridge re-dispatch (#18951). */
    public static function propertyFetchDeclaredSlot(
        Object_ $object,
        Value $obj,
        string $class,
        string $name,
        int $classId,
        bool $forWrite = false
    ): Variable {
        $context = $object->jitContext();
        $className = $object->classNameForId($classId);
        $classLc = strtolower(ltrim($class, '\\'));
        if (\in_array($classLc, ['static', 'self', 'parent'], true)) {
            $runtimeFetch = self::tryPropertyFetchByRuntimeClass($object, $obj, $name, $forWrite);
            if (null !== $runtimeFetch) {
                return $runtimeFetch;
            }
        }
        $nameId = $object->propNameIdFor($name);
        $hasProp = false;
        if (null !== $nameId) {
            foreach ($object->propertySetsForClass($classId) as $propset) {
                if ($propset[0] === $nameId) {
                    $hasProp = true;
                    break;
                }
            }
        }
        if (!$hasProp) {
            // Classes that allow dynamic properties must define the slot on *this* class.
            // tryPropertyFetchByRuntimeClass can pick another class that already declared the
            // same name (e.g. user `C::$x` while writing `stdClass::$x`) and skip defineProperty,
            // so later property_exists() folds false for the dynamic class (#32688 / #10643).
            if (!$object->allowsDynamicProperties($classId)) {
                $runtimeFetch = self::tryPropertyFetchByRuntimeClass($object, $obj, $name, $forWrite);
                if (null !== $runtimeFetch) {
                    return $runtimeFetch;
                }
            }
            $object->defineProperty($classId, $name, $object->externalPropertyJitType($class, $name));
            $nameId = $object->propNameIdAfterDefine($name);
        }
        $propset = $object->resolvePropertySetForNameId($classId, $nameId);
        if (null !== $propset) {
                $slot = $object->propertySlotPtr($obj, $propset[3]);
                if (Variable::TYPE_VALUE === $propset[2]) {
                    $valueType = $context->getTypeFromString('__value__');
                    // Write-only FETCH (ZEND_ASSIGN_OBJ) must not entryAlloca: that parks the
                    // builder at function entry while ARG_RECV's value-copy is still open,
                    // so the store is emitted in entry and the ctor body never runs (#32349).
                    if ($forWrite) {
                        $storage = $context->builder->alloca($valueType);
                    } else {
                        $storage = BasicBlockHelper::entryAlloca($context, $valueType);
                    }
                    // ??= BP_VAR_IS must see the live slot (UNDEF/null vs set). WRITE used to
                    // skip the load, so coalesce took the left branch on an empty alloca
                    // and never stored (#33748 / re-#32880). Keep current-block alloca
                    // for #32349 (do not entryAlloca on the write path).
                    $valueMap = $context->structFieldMap['__value__'];
                    $context->builder->store(
                        $context->getTypeFromString('int8')->constInt(Variable::TYPE_NULL, false),
                        $context->builder->structGep($storage, $valueMap['type'])
                    );
                    $context->builder->call(
                        $context->lookupFunction('__object__load_value_slot'),
                        $slot,
                        $storage
                    );
                    $var = new Variable(
                        $context,
                        $propset[2],
                        Variable::KIND_VARIABLE,
                        $storage,
                    );
                    $var->objectPropertySlot = $slot;
                    $var->objectPropertyType = $propset[2];
                    $var->objectPropertyReceiver = $obj;
                    $var->objectPropertyName = $propset[1];
                    $var->objectPropertyClassName = $className;
                    $var->objectPropertyDnfArms = $object->dnfArmsForProperty($classId, $propset[1]);
                    $object->recordSlotReceiver($slot, $obj);

                    return $var;
                }
                $loaded = $context->builder->load($slot);
                if (Variable::TYPE_HASHTABLE === $propset[2]) {
                    $htPtr = $context->builder->pointerCast(
                        $loaded,
                        $context->getTypeFromString('__hashtable__*')
                    );
                    $var = new Variable(
                        $context,
                        Variable::TYPE_HASHTABLE,
                        Variable::KIND_VALUE,
                        $htPtr
                    );
                    $var->objectPropertySlot = $slot;
                    $var->objectPropertyType = $propset[2];
                    $var->objectPropertyReceiver = $obj;
                    $var->objectPropertyName = $propset[1];
                    $var->objectPropertyClassName = $className;
                    $var->objectPropertyDnfArms = $object->dnfArmsForProperty($classId, $propset[1]);
                    $object->recordSlotReceiver($slot, $obj);

                    return $var;
                }
                // Slot holds a pointer to the native scalar (int64*/double*/int1*), not the
                // scalar bits themselves. Casting void*→int64 (ptrtoint) or void*→double
                // (illegal bitcast) made promoted float props fail verify and int props
                // read the wrong value when a __value__* was stored (#24008).
                $llvmType = Variable::getStringType($propset[2]);
                if (\in_array($propset[2], [
                    Variable::TYPE_NATIVE_LONG,
                    Variable::TYPE_NATIVE_BOOL,
                    Variable::TYPE_NATIVE_DOUBLE,
                ], true)) {
                    $llvmType .= '*';
                    // Unset / never-assigned typed natives store null void* (#33007). Raise
                    // before casting null→scalar* (UB → 0). isset must not use this path —
                    // {@see Object_::propertyIsSet} checks the slot directly.
                    if (!$forWrite && $object->propertySlotRequiresTypedInitGuard($classId, $propset[3])) {
                        $voidPtr = $context->getTypeFromString('void*');
                        $loadedVoid = $context->builder->pointerCast($loaded, $voidPtr);
                        $isNull = $context->builder->icmp(
                            \PHPLLVM\Builder::INT_EQ,
                            $loadedVoid,
                            $voidPtr->constNull()
                        );
                        $fn = $context->builder->getInsertBlock()->getParent();
                        assert($fn instanceof \PHPLLVM\Value\Function_);
                        $raiseBb = $fn->appendBasicBlock('typed_native_prop_uninit_'.$classId.'_'.$propset[3]);
                        $okBb = $fn->appendBasicBlock('typed_native_prop_ok_'.$classId.'_'.$propset[3]);
                        $context->builder->branchIf($isNull, $raiseBb, $okBb);

                        $context->builder->positionAtEnd($raiseBb);
                        $message = sprintf(
                            'Typed property %s::$%s must not be accessed before initialization',
                            MethodVisibility::formatAnonymousScopeForMessage(
                                $object->instancePropertyDeclaringClassName($classId, $propset[1])
                            ),
                            $propset[1]
                        );
                        if (null !== TryCatchHelper::resolveThrowHandler($context)) {
                            TryCatchHelper::emitCatchableClassError($context, 'Error', $message, null);
                            $stillOpen = BasicBlockHelper::tryGetInsertBlock($context);
                            if (null !== $stillOpen && null === $stillOpen->getTerminator()) {
                                TypedPropertyUninitGuard::emitRaiseAndTerminate($context);
                            }
                        } else {
                            $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
                            ErrorRaise::registerDeclarations($context);
                            ErrorRaise::ensureLinked($context);
                            if (\PHPCompiler\JIT\Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
                                ErrorRaise::ensureStandaloneBodies($context);
                            }
                            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
                            ErrorRaise::emitRaise($context, $message);
                            if (\PHPCompiler\JIT\Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
                                $context->builder->call($context->lookupFunction('phpc_jit_abort_if_pending_error'));
                            }
                            TypedPropertyUninitGuard::emitRaiseAndTerminate($context);
                        }

                        $context->builder->positionAtEnd($okBb);
                        $loaded = $context->builder->load($slot);
                    }
                }
                $typed = $context->builder->pointerCast(
                    $loaded,
                    $context->getTypeFromString($llvmType)
                );
                $var = new Variable(
                    $context,
                    $propset[2],
                    Variable::KIND_VALUE,
                    $typed,
                );
                $var->objectPropertySlot = $slot;
                $var->objectPropertyType = $propset[2];
                $var->objectPropertyReceiver = $obj;
                $var->objectPropertyName = $propset[1];
                $var->objectPropertyClassName = $className;
                $var->objectPropertyDnfArms = $object->dnfArmsForProperty($classId, $propset[1]);
                $object->recordSlotReceiver($slot, $obj);

                return $var;
        }
        throw new \LogicException("Could not find property $name for class $classId");
    }

    /**
     * Property fetch/write on a receiver PHPCfg typed as `static` — use __object__.class_id (#31937).
     */
    public static function propertyFetchByRuntimeReceiverClass(
        Object_ $object,
        Value $obj,
        string $name,
        bool $forWrite = false
    ): ?Variable {
        return self::tryPropertyFetchByRuntimeClass($object, $obj, $name, $forWrite);
    }

    /**
     * When the static declaring class lacks a JIT slot, resolve via runtime class_id (#17391).
     */
    private static function tryPropertyFetchByRuntimeClass(
        Object_ $object,
        Value $obj,
        string $name,
        bool $forWrite = false
    ): ?Variable {
        $candidates = [];
        foreach ($object->allClassNamesById() as $id => $className) {
            if (null !== $object->resolvePropertySlot($className, $name)) {
                $candidates[(int) $id] = $className;
            }
        }
        if ([] === $candidates) {
            return null;
        }
        // Living Dom\Attr::$value|nodeValue shares the name "value" with
        // SensitiveParameterValue — multi-candidate dispatch returns a detached
        // TYPE_VALUE box that cannot accept assigns (#27108). Prefer Dom\Attr
        // declared slots when the user-script document is living Dom\*.
        $propLc = strtolower($name);
        if (\in_array($propLc, ['value', 'nodevalue'], true)
            && null !== \PHPCompiler\ext\dom\JitDomLoadXMLUserScript::lastDocumentClass()
            && str_starts_with(
                (string) \PHPCompiler\ext\dom\JitDomLoadXMLUserScript::lastDocumentClass(),
                'Dom\\'
            )
        ) {
            foreach ($candidates as $id => $className) {
                $classLc = strtolower(str_replace('/', '\\', ltrim($className, '\\')));
                if ('dom\\attr' === $classLc || 'domattr' === $classLc) {
                    return self::propertyFetchOrdinary($object, $obj, $className, $name, $id, $forWrite);
                }
            }
        }
        if (1 === \count($candidates)) {
            $classId = array_key_first($candidates);
            $className = $candidates[$classId];

            return self::propertyFetchOrdinary($object, $obj, $className, $name, $classId, $forWrite);
        }

        return self::propertyFetchByRuntimeClassDispatch($object, $obj, $name, $candidates, $forWrite);
    }

    /**
     * @param array<int, string> $candidates class_id => class name
     */
    private static function propertyFetchByRuntimeClassDispatch(
        Object_ $object,
        Value $obj,
        string $name,
        array $candidates,
        bool $forWrite = false
    ): Variable {
        $context = $object->jitContext();
        $map = $context->structFieldMap['__object__'];
        $runtimeClassId = $context->builder->load(
            $context->builder->structGep($obj, $map['class_id'])
        );
        $fn = BasicBlockHelper::parentFunction($context);
        $entry = $context->builder->getInsertBlock();
        $done = $fn->appendBasicBlock('prop_fetch_rt_done');
        $exit = $fn->appendBasicBlock('prop_fetch_rt_exit');
        $fallback = $fn->appendBasicBlock('prop_fetch_rt_fallback');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__'));
        $valueMap = $context->structFieldMap['__value__'];
        $context->builder->store(
            $context->getTypeFromString('int8')->constInt(Variable::TYPE_NULL, false),
            $context->builder->structGep($resultSlot, $valueMap['type'])
        );
        $i64 = $context->getTypeFromString('int64');
        $checkBlock = $entry;
        $lastKey = array_key_last($candidates);
        foreach ($candidates as $classId => $className) {
            $context->builder->positionAtEnd($checkBlock);
            $match = $context->builder->icmp(
                Builder::INT_EQ,
                $runtimeClassId,
                $i64->constInt($classId, false)
            );
            $caseBlock = $fn->appendBasicBlock('prop_fetch_rt_class_'.$classId);
            // Last miss jumps straight to $fallback — do not also branch($fallback) after the
            // loop (that placed a terminator mid-block: "Terminator found in the middle…"; #26757).
            $nextBlock = $classId === $lastKey
                ? $fallback
                : $fn->appendBasicBlock('prop_fetch_rt_try_'.$classId);
            $context->builder->branchIf($match, $caseBlock, $nextBlock);
            $context->builder->positionAtEnd($caseBlock);
            $fetched = self::propertyFetchOrdinary($object, $obj, $className, $name, $classId, $forWrite);
            self::boxFetchedPropertyIntoValue($object, $resultSlot, $fetched, $fetched->objectPropertyType ?? $fetched->type);
            $context->builder->branch($done);
            $checkBlock = $nextBlock;
        }
        // Only emit an explicit miss edge when the last candidate did not already target fallback.
        if ($checkBlock !== $fallback) {
            $context->builder->positionAtEnd($checkBlock);
            $context->builder->branch($fallback);
        }
        $context->builder->positionAtEnd($fallback);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $resultSlot)
        );
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
        $context->builder->branch($exit);
        $context->builder->positionAtEnd($exit);

        return new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $resultSlot
        );
    }

    public static function boxFetchedPropertyIntoValue(
        Object_ $object,
        Value $destSlot,
        Variable $fetched,
        int $propertyType
    ): void {
        $context = $object->jitContext();
        $destPtr = JitValueBox::pointer($context, $destSlot);
        if (Variable::TYPE_VALUE === $propertyType) {
            // Already-boxed fetch (runtime class dispatch / empty-prop foreach dummy)
            // has no objectPropertySlot — copy the __value__ rather than load_value_slot (#27226).
            if (null === $fetched->objectPropertySlot) {
                if (Variable::TYPE_VALUE === $fetched->type && null !== $fetched->value) {
                    JitValueBox::copyFromPointer(
                        $context,
                        $destSlot,
                        JitValueBox::valuePtrFromVariable($context, $fetched)
                    );

                    return;
                }
                throw new \LogicException(
                    'boxFetchedPropertyIntoValue TYPE_VALUE without objectPropertySlot (#27226)'
                );
            }
            $context->builder->call(
                $context->lookupFunction('__object__load_value_slot'),
                $fetched->objectPropertySlot,
                $destSlot
            );

            return;
        }
        if (Variable::TYPE_HASHTABLE === $propertyType) {
            $htPtr = $context->builder->pointerCast(
                $context->builder->load($fetched->objectPropertySlot),
                $context->getTypeFromString('__hashtable__*')
            );
            $context->builder->call(
                $context->lookupFunction('__value__writeHashtable'),
                $destPtr,
                $htPtr
            );

            return;
        }
        if (Variable::TYPE_NATIVE_LONG === $propertyType) {
            $context->builder->call(
                $context->lookupFunction('__value__writeLong'),
                $destPtr,
                $context->builder->load($fetched->value)
            );

            return;
        }
        if (Variable::TYPE_NATIVE_BOOL === $propertyType) {
            JitValueBox::writeBool(
                $context,
                $destSlot,
                $context->builder->load($fetched->value)
            );

            return;
        }
        if (Variable::TYPE_NATIVE_DOUBLE === $propertyType) {
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $destPtr,
                $context->builder->load($fetched->value)
            );

            return;
        }
        if (Variable::TYPE_STRING === $propertyType) {
            // KIND_VALUE already holds `__string__*`; load() would pass `__string__` by value (#27108).
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                $destPtr,
                $context->helper->loadValue($fetched)
            );

            return;
        }
        if (Variable::TYPE_OBJECT === $propertyType) {
            $context->builder->call(
                $context->lookupFunction('__value__writeObject'),
                $destPtr,
                $context->helper->loadValue($fetched)
            );

            return;
        }

        throw new \LogicException(
            'Dynamic property fetch JIT box unsupported type: '.Variable::getStringType($propertyType)
        );
    }

    /**
     * Fetch-arm objectPropertySlot SSA may not dominate later ARG_SEND / var_dump (#33760).
     * Re-emit propertySlotPtr in the current block when receiver/class/name are known.
     */
    public static function dominatingSlotPtr(Object_ $object, Variable $variable): Value
    {
        $context = $object->jitContext();
        $receiver = null !== $variable->objectPropertyReceiverOp
            ? self::reloadPropertyReceiver($context, $variable->objectPropertyReceiverOp)
            : $variable->objectPropertyReceiver;
        if (
            null !== $receiver
            && null !== $variable->objectPropertyClassName
            && null !== $variable->objectPropertyName
        ) {
            $resolved = $object->resolvePropertySlot(
                $variable->objectPropertyClassName,
                $variable->objectPropertyName
            );
            if (null !== $resolved) {
                return $object->propertySlotPtr(
                    $receiver,
                    $resolved[1]
                );
            }
        }
        if (null === $variable->objectPropertySlot) {
            throw new \LogicException('objectPropertySlot requires objectPropertyType');
        }

        return $variable->objectPropertySlot;
    }

    private static function reloadPropertyReceiver(
        \PHPCompiler\JIT\Context $context,
        \PHPCfg\Operand $objOp
    ): Value {
        $name = \PHPCompiler\JIT\OperandName::resolve($objOp);
        if (null !== $name && '' !== $name) {
            $resolved = $context->resolveRefAliasName($name);
            if (isset($context->namedVariableBindings[$resolved])) {
                $bound = $context->namedVariableBindings[$resolved];
                if (Variable::TYPE_OBJECT === $bound->type) {
                    return $context->helper->loadValue($bound);
                }
            }
        }
        $var = $context->getVariableFromOpInScopes($objOp);
        if (Variable::TYPE_OBJECT === $var->type) {
            return $context->helper->loadValue($var);
        }
        if (Variable::TYPE_VALUE === $var->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $var)
            );
        }

        throw new \LogicException(
            'Property fetch receiver must be object or object-valued property, got '
            .Variable::getStringType($var->type)
        );
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin\Type;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
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
        int $classId
    ): Variable {
        $classLc = strtolower(str_replace('/', '\\', ltrim($class, '\\')));
        if (\PHPCompiler\ext\dom\JitDomNodeChildProperty::isDomNodeChildProperty($classLc, strtolower($name))) {
            return \PHPCompiler\ext\dom\JitDomNodeChildProperty::fetch($object, $obj, $name);
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
            return \PHPCompiler\ext\dom\JitDomElementTextContent::fetchNamed($object, $obj, $name);
        }
        if (\PHPCompiler\ext\dom\JitDomNodeListLength::isDomNodeListLength($classLc, strtolower($name))) {
            return \PHPCompiler\ext\dom\JitDomNodeListLength::fetch($object, $obj);
        }
        if (\PHPCompiler\ext\dom\JitDomDocumentElement::isDomDocumentElement($classLc, strtolower($name))) {
            return \PHPCompiler\ext\dom\JitDomDocumentElement::fetch($object, $obj);
        }

        return self::propertyFetchDeclaredSlot($object, $obj, $class, $name, $classId);
    }

    /** Slot read without ext/dom live-bridge re-dispatch (#18951). */
    public static function propertyFetchDeclaredSlot(
        Object_ $object,
        Value $obj,
        string $class,
        string $name,
        int $classId
    ): Variable {
        $context = $object->jitContext();
        $className = $object->classNameForId($classId);
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
            $runtimeFetch = self::tryPropertyFetchByRuntimeClass($object, $obj, $name);
            if (null !== $runtimeFetch) {
                return $runtimeFetch;
            }
            $object->defineProperty($classId, $name, $object->externalPropertyJitType($class, $name));
            $nameId = $object->propNameIdAfterDefine($name);
        }
        foreach ($object->propertySetsForClass($classId) as $propset) {
            if ($propset[0] === $nameId) {
                $slot = $object->propertySlotPtr($obj, $propset[3]);
                $loaded = $context->builder->load($slot);
                if (Variable::TYPE_VALUE === $propset[2]) {
                    $valueType = $context->getTypeFromString('__value__');
                    $storage = BasicBlockHelper::entryAlloca($context, $valueType);
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
                $llvmType = Variable::getStringType($propset[2]);
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
        }
        throw new \LogicException("Could not find property $name for class $classId");
    }

    /**
     * When the static declaring class lacks a JIT slot, resolve via runtime class_id (#17391).
     */
    private static function tryPropertyFetchByRuntimeClass(
        Object_ $object,
        Value $obj,
        string $name
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
        if (1 === \count($candidates)) {
            $classId = array_key_first($candidates);
            $className = $candidates[$classId];

            return self::propertyFetchOrdinary($object, $obj, $className, $name, $classId);
        }

        return self::propertyFetchByRuntimeClassDispatch($object, $obj, $name, $candidates);
    }

    /**
     * @param array<int, string> $candidates class_id => class name
     */
    private static function propertyFetchByRuntimeClassDispatch(
        Object_ $object,
        Value $obj,
        string $name,
        array $candidates
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
            $nextBlock = $classId === $lastKey
                ? $fallback
                : $fn->appendBasicBlock('prop_fetch_rt_try_'.$classId);
            $context->builder->branchIf($match, $caseBlock, $nextBlock);
            $context->builder->positionAtEnd($caseBlock);
            $fetched = self::propertyFetchOrdinary($object, $obj, $className, $name, $classId);
            self::boxFetchedPropertyIntoValue($object, $resultSlot, $fetched, $fetched->objectPropertyType ?? $fetched->type);
            $context->builder->branch($done);
            $checkBlock = $nextBlock;
        }
        $context->builder->positionAtEnd($checkBlock);
        $context->builder->branch($fallback);
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
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                $destPtr,
                $context->builder->load($fetched->value)
            );

            return;
        }
        if (Variable::TYPE_OBJECT === $propertyType) {
            $context->builder->call(
                $context->lookupFunction('__value__writeObject'),
                $destPtr,
                $context->builder->load($fetched->value)
            );

            return;
        }

        throw new \LogicException(
            'Dynamic property fetch JIT box unsupported type: '.Variable::getStringType($propertyType)
        );
    }
}

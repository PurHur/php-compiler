<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin\Type;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM;
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

<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCfg\Operand;
use PHPTypes\Type;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\VM\VmIsset;
use PHPCompiler\Web\Superglobals;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering bodies for isset() — delegates semantic guards to {@see VmIsset} (#10170).
 */
final class IssetHelperLlvm
{
    public static function compile(
        Context $context,
        Variable $container,
        ?Variable $dim,
        ?Operand $dimOp = null,
        ?Operand $containerOp = null,
        bool $issetOnProperty = false
    ): Value {
        if (null === $dim) {
            return self::compileVariableIsSet($context, $container);
        }

        return self::compileOffsetIsSet(
            $context,
            $container,
            $dim,
            $dimOp,
            $containerOp,
            $issetOnProperty
        );
    }

    /**
     * Standalone AOT: test hashtable keys via readStringKeyValue + null check (#767, #784).
     */
    private static function compileSuperglobalNullReadIsset(
        Context $context,
        Variable $container,
        Variable $dim
    ): Value {
        $ht = HashTableHelper::loadHashtablePointer($context, $container);
        $keyStr = $context->helper->loadValue($dim);
        $valPtr = $context->builder->call(
            $context->lookupFunction('__hashtable__peekStringKeyValue'),
            $ht,
            $keyStr
        );

        return $context->builder->icmp(
            Builder::INT_NE,
            $valPtr,
            $valPtr->typeOf()->constNull()
        );
    }

    private static function compileVariableIsSet(Context $context, Variable $var): Value
    {
        $loaded = $context->helper->loadValue($var);
        $i1 = $context->getTypeFromString('int1');

        switch ($var->type) {
            case Variable::TYPE_NULL:
                return $i1->constInt(0, false);
            case Variable::TYPE_NATIVE_LONG:
            case Variable::TYPE_NATIVE_BOOL:
            case Variable::TYPE_NATIVE_DOUBLE:
                return $i1->constInt(1, false);
            case Variable::TYPE_STRING:
                $null = $context->getTypeFromString('__string__*')->constNull();

                return $context->builder->icmp(Builder::INT_NE, $loaded, $null);
            case Variable::TYPE_VALUE:
                $valuePtr = JitValueBox::valuePtrFromVariable($context, $var);
                $typeField = $context->structFieldMap['__value__']['type'];
                $typeByte = $context->builder->load(
                    $context->builder->structGep($valuePtr, $typeField)
                );
                $nullType = $context->getTypeFromString('int8')->constInt(0, false);

                return $context->builder->icmp(Builder::INT_NE, $typeByte, $nullType);
            case Variable::TYPE_OBJECT:
                $objPtr = Variable::KIND_VALUE === $var->kind
                    ? $var->value
                    : $context->builder->load($var->value);
                $null = $context->getTypeFromString('__object__*')->constNull();

                return $context->builder->icmp(Builder::INT_NE, $objPtr, $null);
            case Variable::TYPE_HASHTABLE:
                $null = $context->getTypeFromString('__hashtable__*')->constNull();

                return $context->builder->icmp(Builder::INT_NE, $loaded, $null);
            default:
                return $i1->constInt(1, false);
        }
    }

    private static function compileOffsetIsSet(
        Context $context,
        Variable $container,
        Variable $dim,
        ?Operand $dimOp,
        ?Operand $containerOp,
        bool $issetOnProperty = false
    ): Value {
        if (VmIsset::issetOnPropertyRejectsArrayContainer($container, $containerOp, $issetOnProperty)) {
            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        if ($container->type === Variable::TYPE_STRING) {
            return self::compileStringOffsetIsSet($context, $container, $dim);
        }
        if ($container->type & Variable::IS_NATIVE_ARRAY) {
            return self::compileNativeArrayOffsetIsSet($context, $container, $dim);
        }
        if ($container->type === Variable::TYPE_HASHTABLE) {
            return self::compileHashTableOffsetIsSet($context, $container, $dim, $dimOp, $containerOp);
        }
        if (Variable::TYPE_VALUE === $container->type) {
            if ($issetOnProperty) {
                return self::compileValueBoxPropertyIsSet(
                    $context,
                    $container,
                    $dim,
                    $dimOp,
                    $containerOp
                );
            }
            $htVar = self::hashtableFromValueBox($context, $container);

            return self::compileHashTableOffsetIsSet($context, $htVar, $dim, $dimOp, $containerOp);
        }
        if (Variable::TYPE_OBJECT === $container->type && null === $container->objectPropertySlot) {
            $propName = VmIsset::literalStringKey($dimOp);
            // Enum tryFrom()/from() temps may lack a precise userType; still probe name/value (#27666).
            $hasObjectType = null !== $containerOp
                && null !== $containerOp->type
                && Type::TYPE_OBJECT === $containerOp->type->type;
            if (
                null !== $propName
                && (
                    $hasObjectType
                    || \PHPCompiler\VM\EnumCasePropertyJitHelper::isBuiltinPropertyName(strtolower($propName))
                )
            ) {
                $class = $hasObjectType ? ($containerOp->type->userType ?? '') : '';
                $objPtr = Variable::KIND_VALUE === $container->kind
                    ? $container->value
                    : $context->builder->load($container->value);

                return $context->type->object->propertyIsSet($objPtr, $class, $propName);
            }
        }
        if (Variable::TYPE_OBJECT === $container->type) {
            // isset($obj->prop) must not use ArrayAccess::offsetExists (#19707).
            if (!$issetOnProperty) {
                $arrayAccessIsset = ArrayAccessHelper::tryCompileOffsetIsSet(
                    $context,
                    $container,
                    $dim,
                    $containerOp
                );
                if (null !== $arrayAccessIsset) {
                    return $arrayAccessIsset;
                }
            }
            $htVar = self::hashtableFromObjectContainer($context, $container, $containerOp);
            $containerUserType = '';
            if (null !== $containerOp && null !== $containerOp->type) {
                $containerUserType = $containerOp->type->userType ?? '';
            }
            if (
                'splobjectstorage' === strtolower($containerUserType)
                && Variable::TYPE_OBJECT === $dim->type
            ) {
                $ht = $context->helper->loadValue($htVar);
                $keyObj = $context->helper->loadValue($dim);

                return $context->builder->call(
                    $context->lookupFunction('__hashtable__offsetIsSetObjectKey'),
                    $ht,
                    $keyObj
                );
            }

            return self::compileHashTableOffsetIsSet($context, $htVar, $dim, $dimOp, $containerOp);
        }

        return $context->getTypeFromString('int1')->constInt(0, false);
    }

    /**
     * isset($boxedObj->prop) when the receiver is a {@see __value__} slot (#27666).
     *
     * Thin AOT class-const / tryFrom results are often TYPE_VALUE rather than TYPE_OBJECT;
     * the hashtable offset path would always yield false for enum name/value.
     */
    private static function compileValueBoxPropertyIsSet(
        Context $context,
        Variable $container,
        Variable $dim,
        ?Operand $dimOp,
        ?Operand $containerOp
    ): Value {
        $i1 = $context->getTypeFromString('int1');
        $propName = VmIsset::literalStringKey($dimOp);
        if (null === $propName) {
            // Dynamic property name on a value-box object: not supported for AOT yet.
            return $i1->constInt(0, false);
        }
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $container);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_OBJECT & 0x7f, false)
        );
        // Also accept VM TYPE_ENUM_CASE (9) when present in a boxed slot.
        $isEnumCase = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(\PHPCompiler\VM\Variable::TYPE_ENUM_CASE & 0x7f, false)
        );
        $isObjectLike = $context->builder->or($isObject, $isEnumCase);

        $fn = BasicBlockHelper::parentFunction($context);
        $objBlock = $fn->appendBasicBlock('isset_value_box_obj');
        $missBlock = $fn->appendBasicBlock('isset_value_box_miss');
        $doneBlock = $fn->appendBasicBlock('isset_value_box_done');
        $context->builder->branchIf($isObjectLike, $objBlock, $missBlock);

        $context->builder->positionAtEnd($missBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($objBlock);
        $objPtr = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        $class = '';
        if (
            null !== $containerOp
            && null !== $containerOp->type
            && Type::TYPE_OBJECT === $containerOp->type->type
        ) {
            $class = $containerOp->type->userType ?? '';
        }
        $propIsset = $context->type->object->propertyIsSet($objPtr, $class, $propName);
        $objEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($i1, 'isset_value_box_phi');
        $phi->addIncoming($i1->constInt(0, false), $missBlock);
        $phi->addIncoming($propIsset, $objEnd);

        return $phi;
    }

    private static function hashtableFromValueBox(Context $context, Variable $container): Variable
    {
        $ht = HashTableHelper::readHashtableFromValueBox($context, $container);

        return new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            $ht
        );
    }

    private static function hashtableFromObjectContainer(
        Context $context,
        Variable $container,
        ?Operand $containerOp
    ): Variable {
        $containerUserType = '';
        if (null !== $containerOp && null !== $containerOp->type) {
            $containerUserType = $containerOp->type->userType ?? '';
        }
        if ('splobjectstorage' === strtolower($containerUserType)) {
            return $context->type->object->splBackingHashtable($container);
        }
        if (null !== $container->objectPropertySlot) {
            if (Variable::TYPE_HASHTABLE === $container->objectPropertyType) {
                $htPtr = $context->builder->pointerCast(
                    $context->builder->load($container->objectPropertySlot),
                    $context->getTypeFromString('__hashtable__*')
                );

                return new Variable(
                    $context,
                    Variable::TYPE_HASHTABLE,
                    Variable::KIND_VALUE,
                    $htPtr
                );
            }
            $storage = JitValueBox::alloc($context);
            $valueMap = $context->structFieldMap['__value__'];
            $context->builder->call(
                $context->lookupFunction('__object__load_value_slot'),
                $container->objectPropertySlot,
                $storage
            );
            $boxed = new Variable(
                $context,
                Variable::TYPE_VALUE,
                Variable::KIND_VARIABLE,
                $storage
            );

            return self::hashtableFromValueBox($context, $boxed);
        }

        throw new \LogicException(
            'isset() with array offset on object containers only supports SplObjectStorage or typed object properties in this compiler build'
        );
    }

    private static function compileStringOffsetIsSet(Context $context, Variable $container, Variable $dim): Value
    {
        $i1 = $context->getTypeFromString('int1');
        // zend_isset_dim_slow: only IS_LONG or strict integral numeric string (#22895)
        if (Variable::TYPE_OBJECT === $dim->type || Variable::TYPE_HASHTABLE === $dim->type) {
            return $i1->constInt(0, false);
        }
        if (Variable::TYPE_STRING === $dim->type) {
            $lit = $dim->compileTimeString;
            if (null === $lit || !\PHPCompiler\VM\Variable::isIntegralNumericString($lit)) {
                return $i1->constInt(0, false);
            }
            $dim = new Variable(
                $context,
                Variable::TYPE_NATIVE_LONG,
                Variable::KIND_VALUE,
                $context->constantFromInteger((int) trim($lit))
            );
        } elseif (Variable::TYPE_NATIVE_DOUBLE === $dim->type) {
            // zend_isset_dim: float→int Implicit-conversion E_DEPRECATED (#29557).
            $truncated = \PHPCompiler\ext\standard\JitIntdiv::floatToLongWithPrecisionWarning(
                $context,
                $context->helper->loadValue($dim)
            );
            $dim = new Variable(
                $context,
                Variable::TYPE_NATIVE_LONG,
                Variable::KIND_VALUE,
                $truncated
            );
        } elseif (Variable::TYPE_NATIVE_BOOL === $dim->type) {
            // Silent coerce (no string-offset cast warning) — matches zend_isset_dim (#29558).
            $dim = $dim->castTo(Variable::TYPE_NATIVE_LONG);
        } elseif (Variable::TYPE_NULL === $dim->type) {
            $dim = new Variable(
                $context,
                Variable::TYPE_NATIVE_LONG,
                Variable::KIND_VALUE,
                $context->constantFromInteger(0)
            );
        } elseif (Variable::TYPE_NATIVE_LONG !== $dim->type) {
            return $i1->constInt(0, false);
        }
        $str = $context->helper->loadValue($container);
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load(
            $context->builder->structGep($str, $map['length'])
        );
        $index = $context->helper->loadValue($dim);
        $offset = StringOffsetHelper::normalizeOffset($context, $index, $len);
        $zero = $context->getTypeFromString('size_t')->constInt(0, false);
        $nonNeg = $context->builder->icmp(Builder::INT_UGE, $offset, $zero);
        $inRange = $context->builder->icmp(Builder::INT_ULT, $offset, $len);

        return $context->builder->and($inRange, $nonNeg);
    }

    private static function compileHashTableOffsetIsSet(
        Context $context,
        Variable $container,
        Variable $dim,
        ?Operand $dimOp,
        ?Operand $containerOp
    ): Value {
        $container = HashTableHelper::asDetachedHashtable($context, $container);
        if (Variable::TYPE_STRING === $container->type) {
            return self::compileStringOffsetIsSet($context, $container, $dim);
        }
        if (
            Builtin::LOAD_TYPE_STANDALONE === $context->loadType
            && Variable::TYPE_HASHTABLE === $container->type
            && Variable::TYPE_STRING === $dim->type
            && null !== $container->superglobalName
            && '_FILES' !== $container->superglobalName
        ) {
            return self::compileSuperglobalNullReadIsset($context, $container, $dim);
        }
        $superglobalName = VmIsset::superglobalName($container, $containerOp, VmIsset::isSelfHostAot());
        if (null !== $superglobalName) {
            if ('GLOBALS' === $superglobalName) {
                return GlobalsTableInit::offsetIsSet($context, $dim);
            }
            $key = $dim->compileTimeString ?? VmIsset::literalStringKey($dimOp);
            if (
                null !== $key
                && !SuperglobalInit::requiresRuntimeOffsetIsSet($context, $superglobalName)
            ) {
                $known = SuperglobalInit::compileTimeOffsetIsSet(
                    $context,
                    $superglobalName,
                    $key
                );
                if (true === $known) {
                    return $context->getTypeFromString('int1')->constInt(1, false);
                }
            }
            $ht = HashTableHelper::loadHashtablePointer($context, $container);

            return HashTableHelper::offsetIsSetDim($context, $ht, $dim);
        }

        $ht = HashTableHelper::loadHashtablePointer($context, $container);

        return HashTableHelper::offsetIsSetDim($context, $ht, $dim);
    }

    private static function compileNativeArrayOffsetIsSet(Context $context, Variable $container, Variable $dim): Value
    {
        if (Variable::TYPE_NATIVE_LONG !== $dim->type) {
            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        $index = $context->helper->loadValue($dim);
        $size = $context->constantFromInteger($container->nextFreeElement, 'int32');
        $i32 = $context->getTypeFromString('int32');
        $inRange = $context->builder->icmp(Builder::INT_SLT, $index, $size);
        $nonNeg = $context->builder->icmp(Builder::INT_SGE, $index, $i32->constInt(0, false));

        return $context->builder->and($inRange, $nonNeg);
    }
}

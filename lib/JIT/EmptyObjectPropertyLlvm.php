<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCfg\Operand;
use PHPTypes\Type;
use PHPCompiler\ext\standard\boolval;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\VM\Variable as VmVariable;
use PHPCompiler\VM\VmEmpty;
use PHPCompiler\VM\VmIsset;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering bodies for empty($obj->prop) — delegates semantic guards to {@see VmEmpty} (#10268).
 */
final class EmptyObjectPropertyLlvm
{
    public static function compile(
        Context $context,
        Variable $container,
        Variable $dim,
        ?Operand $dimOp,
        ?Operand $containerOp
    ): Value {
        $propName = VmIsset::literalStringKey($dimOp);
        if (null !== $propName) {
            $enumEmpty = self::tryCompileEnumCasePropertyEmpty(
                $context,
                $container,
                $containerOp,
                $propName
            );
            if (null !== $enumEmpty) {
                return $enumEmpty;
            }
        }
        if (
            null !== $propName
            && Variable::TYPE_OBJECT === $container->type
            && null !== $containerOp
            && null !== $containerOp->type
            && Type::TYPE_OBJECT === $containerOp->type->type
        ) {
            $class = $containerOp->type->userType ?? '';
            $object = $context->type->object;
            assert($object instanceof Object_);
            $objPtr = Variable::KIND_VALUE === $container->kind
                ? $container->value
                : $context->builder->load($container->value);
            $hookValue = PropertyHookDispatch::tryEmitPropertyGet(
                $context,
                $objPtr,
                $class,
                $propName,
                $context->jitCurrentBlock
            );
            if (null !== $hookValue) {
                return self::compileEmptyFromFetchedValue($context, $hookValue);
            }
            $resolved = $object->resolvePropertySlot($class, $propName);
            if (null !== $resolved && $object->propertySlotIsTypedValue($resolved[0], $resolved[1])) {
                $fetched = $object->propertyFetch($objPtr, $class, $propName);

                return self::compileEmptyFromFetchedValue($context, $fetched);
            }
        }

        $isset = IssetHelper::compile($context, $container, $dim, $dimOp, $containerOp);

        return $context->builder->not($isset);
    }

    /**
     * empty(value): true when uninitialized typed slot or value is falsy (#6787).
     */
    public static function compileEmptyFromValue(Context $context, Variable $var): Value
    {
        return self::compileEmptyFromFetchedValue($context, $var);
    }

    /**
     * empty(slot): true when uninitialized typed or value is falsy — no read guard (#6787).
     */
    private static function compileEmptyFromFetchedValue(Context $context, Variable $fetched): Value
    {
        if (Variable::TYPE_VALUE !== $fetched->type) {
            $truthy = (new boolval())->call($context, $fetched);

            return $context->builder->not($truthy);
        }
        $valuePtr = $fetched->valueBoxAliasPtr ?? null;
        if (null === $valuePtr && Variable::KIND_VARIABLE === $fetched->kind) {
            $valuePtr = JitValueBox::pointer($context, $fetched->value);
        }
        if (null === $valuePtr) {
            $truthy = (new boolval())->call($context, $fetched);

            return $context->builder->not($truthy);
        }
        $valuePtr = JitValueBox::normalizeValuePtr($context, $valuePtr);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $isUndef = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_UNDEFINED, false)
        );
        $truthy = (new boolval())->call($context, $fetched);
        $valueEmpty = $context->builder->not($truthy);

        return $context->builder->select(
            $isUndef,
            $context->constantFromBool(VmEmpty::uninitializedSlotCountsAsEmpty(VmVariable::TYPE_UNDEFINED)),
            $valueEmpty
        );
    }

    /**
     * empty($enumCase->name) / empty($enumCase->value) — fetch magic read then falsy test (#9890).
     */
    private static function tryCompileEnumCasePropertyEmpty(
        Context $context,
        Variable $container,
        ?Operand $containerOp,
        string $propName
    ): ?Value {
        $object = $context->type->object;
        if (!$object instanceof Object_) {
            return null;
        }
        $nameLc = strtolower($propName);
        if (Variable::TYPE_OBJECT === $container->type) {
            $class = '';
            if (null !== $containerOp && null !== $containerOp->type) {
                $class = $containerOp->type->userType ?? '';
            }
            if ('' === $class) {
                return null;
            }
            $classId = $object->lookup($class);
            if (!$object->isEnumClassId($classId)) {
                return null;
            }
            if ('name' !== $nameLc && ('value' !== $nameLc || !$object->enumHasBacking($classId))) {
                return $context->constantFromBool(true);
            }
            $objPtr = Variable::KIND_VALUE === $container->kind
                ? $container->value
                : $context->builder->load($container->value);
            $fetched = $object->propertyFetch($objPtr, $class, $propName);

            return self::compileEmptyFromFetchedValue($context, $fetched);
        }
        if (Variable::TYPE_VALUE !== $container->type || Variable::KIND_VALUE !== $container->kind) {
            return null;
        }
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $container);
        $map = $context->structFieldMap['__value__'];
        if (null === $map || !isset($map['type'])) {
            return null;
        }
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        if (!method_exists($typeByte, 'isConstant') || !$typeByte->isConstant()) {
            return null;
        }
        if ((int) $typeByte->getConstantValue() !== VmVariable::TYPE_ENUM_CASE) {
            return null;
        }
        if ('name' !== $nameLc && 'value' !== $nameLc) {
            return $context->constantFromBool(true);
        }
        $objPtr = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        $fetched = $object->propertyFetch($objPtr, '', $propName);

        return self::compileEmptyFromFetchedValue($context, $fetched);
    }
}

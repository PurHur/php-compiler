<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\Type\Object_ as JitObjectType;
use PHPCompiler\VM\Variable as VmVariable;

/**
 * Shared compile-time operand labels for JIT TypeError messages (#6236).
 */
final class JitOperandTypeLabel
{
    public static function givenLabel(Context $context, Variable $arg): string
    {
        $enumLabel = self::compileTimeEnumClassName($context, $arg);
        if (null !== $enumLabel) {
            return $enumLabel;
        }
        if (Variable::TYPE_OBJECT === $arg->type) {
            $classLabel = self::compileTimeObjectClassName($context, $arg);
            if (null !== $classLabel) {
                return $classLabel;
            }
        }

        return match ($arg->type) {
            Variable::TYPE_NATIVE_LONG => 'int',
            Variable::TYPE_NATIVE_DOUBLE => 'float',
            Variable::TYPE_NATIVE_BOOL => 'bool',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_HASHTABLE => 'array',
            Variable::TYPE_OBJECT => 'object',
            default => 'mixed',
        };
    }

    public static function compileTimeEnumClassName(Context $context, Variable $arg): ?string
    {
        if (Variable::TYPE_OBJECT === $arg->type) {
            return self::compileTimeObjectEnumClassName($context, $arg);
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            return self::compileTimeValueBoxEnumClassName($context, $arg);
        }

        return null;
    }

    private static function compileTimeObjectEnumClassName(Context $context, Variable $arg): ?string
    {
        $classId = self::constantObjectClassId($context, $arg);
        if (null === $classId) {
            return null;
        }
        $jitObject = $context->type->object;
        if (!$jitObject instanceof JitObjectType) {
            return null;
        }
        $lc = strtolower(ltrim($jitObject->classNameForId($classId), '\\'));
        if (!isset($jitObject->enums[$lc])) {
            return null;
        }

        return $jitObject->classNameForId($classId);
    }

    private static function compileTimeValueBoxEnumClassName(Context $context, Variable $arg): ?string
    {
        if (Variable::KIND_VALUE !== $arg->kind) {
            return null;
        }
        $map = $context->structFieldMap['__value__'] ?? null;
        if (null === $map || !isset($map['type'])) {
            return null;
        }
        $typeByte = $context->builder->load(
            $context->builder->structGep($arg->value, $map['type'])
        );
        if (!method_exists($typeByte, 'isConstant') || !$typeByte->isConstant()) {
            return null;
        }
        $type = (int) $typeByte->getConstantValue();
        if (VmVariable::TYPE_OBJECT === $type) {
            $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
            $obj = $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                $valuePtr
            );
            $objVar = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $obj);

            return self::compileTimeObjectEnumClassName($context, $objVar)
                ?? self::compileTimeObjectClassName($context, $objVar);
        }
        if (VmVariable::TYPE_ENUM_CASE !== $type) {
            return null;
        }
        $enumMap = $context->structFieldMap['__enum_case__'] ?? null;
        if (null === $enumMap || !isset($enumMap['class_id'])) {
            return null;
        }
        $classIdVal = $context->builder->load(
            $context->builder->structGep($arg->value, $enumMap['class_id'])
        );
        if (!method_exists($classIdVal, 'isConstant') || !$classIdVal->isConstant()) {
            return null;
        }
        $classId = (int) $classIdVal->getConstantValue();
        $jitObject = $context->type->object;
        if (!$jitObject instanceof JitObjectType) {
            return null;
        }

        return $jitObject->classNameForId($classId);
    }

    private static function compileTimeObjectClassName(Context $context, Variable $arg): ?string
    {
        $classId = self::constantObjectClassId($context, $arg);
        if (null === $classId) {
            return null;
        }
        $jitObject = $context->type->object;
        if (!$jitObject instanceof JitObjectType) {
            return null;
        }

        return $jitObject->classNameForId($classId);
    }

    private static function constantObjectClassId(Context $context, Variable $arg): ?int
    {
        if (Variable::KIND_VALUE !== $arg->kind) {
            return null;
        }
        $objMap = $context->structFieldMap['__object__'] ?? null;
        if (null === $objMap || !isset($objMap['class_id'])) {
            return null;
        }
        $classIdVal = $context->builder->load(
            $context->builder->structGep($arg->value, $objMap['class_id'])
        );
        if (!method_exists($classIdVal, 'isConstant') || !$classIdVal->isConstant()) {
            return null;
        }
        if (!method_exists($classIdVal, 'getConstantValue')) {
            return null;
        }

        return (int) $classIdVal->getConstantValue();
    }
}

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
                // Legacy Resource wrappers → zend_zval_type_name "resource" (#29594 / #29559).
                return 0 === strcasecmp($classLabel, 'Resource') ? 'resource' : $classLabel;
            }
        }

        return match ($arg->type) {
            Variable::TYPE_NATIVE_LONG => 'int',
            Variable::TYPE_NATIVE_DOUBLE => 'float',
            Variable::TYPE_NATIVE_BOOL => self::nativeBoolLiteralLabel($arg),
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_HASHTABLE => 'array',
            Variable::TYPE_OBJECT => 'object',
            default => 'mixed',
        };
    }

    /**
     * zend_execute.c — bool actuals print true/false, not bool (#29097).
     */
    private static function nativeBoolLiteralLabel(Variable $arg): string
    {
        $value = $arg->value;
        if (method_exists($value, 'isConstant') && $value->isConstant()
            && method_exists($value, 'getConstantValue')
        ) {
            return 0 !== (int) $value->getConstantValue() ? 'true' : 'false';
        }

        return 'bool';
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
        if (Variable::KIND_VALUE !== $arg->kind && Variable::KIND_VARIABLE !== $arg->kind) {
            return null;
        }
        $map = $context->structFieldMap['__value__'] ?? null;
        if (null === $map || !isset($map['type'])) {
            return null;
        }
        // NestedJIT mid-{main} can leave KIND_VALUE operands as __value__** slots (#21041).
        // Always resolve via valuePtrFromVariable — never structGep raw ->value.
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        if (!method_exists($typeByte, 'isConstant') || !$typeByte->isConstant()) {
            return null;
        }
        if (!method_exists($typeByte, 'getConstantValue')) {
            return null;
        }
        $type = (int) $typeByte->getConstantValue();
        if (VmVariable::TYPE_OBJECT === $type) {
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
        // Same overlay as JitStringBuiltinArg::emitRuntimeBoxedEnumCaseReject — enum fields on value box.
        $classIdVal = $context->builder->load(
            $context->builder->structGep($valuePtr, $enumMap['class_id'])
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
        $receiver = $arg->value;
        $tyName = $context->getStringFromType($receiver->typeOf());
        if ('__object__**' === $tyName) {
            $receiver = $context->builder->load($receiver);
        }
        try {
            $classIdVal = $context->builder->load(
                $context->builder->structGep($receiver, $objMap['class_id'])
            );
        } catch (\Throwable) {
            return null;
        }
        if (!method_exists($classIdVal, 'isConstant') || !$classIdVal->isConstant()) {
            return null;
        }
        if (!method_exists($classIdVal, 'getConstantValue')) {
            return null;
        }

        return (int) $classIdVal->getConstantValue();
    }
}

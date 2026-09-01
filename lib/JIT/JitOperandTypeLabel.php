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
                if (0 === strcasecmp($classLabel, 'Resource')) {
                    return 'resource';
                }

                // TypeError "… given": strip anon NUL provenance (#29569 / #26031).
                return \PHPCompiler\MethodVisibility::formatAnonymousScopeForMessage($classLabel);
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
            default => (0 !== ($arg->type & Variable::IS_NATIVE_ARRAY)) ? 'array' : 'mixed',
        };
    }

    /**
     * zend_zval_type_name() — bool actuals are {@code bool} until PROFILE≥8.4 GH-8385 (#31160).
     */
    private static function nativeBoolLiteralLabel(Variable $arg): string
    {
        if (!\PHPCompiler\CompilerVersion::supportsTrueFalseZvalTypeName()) {
            return 'bool';
        }
        $value = $arg->value;
        $literal = self::constantIntFromLlvmValue($context, $value);
        if (null !== $literal) {
            return 0 !== $literal ? 'true' : 'false';
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
        $type = self::constantIntFromLlvmValue($context, $typeByte);
        if (null === $type) {
            return null;
        }
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
        $classId = self::constantIntFromLlvmValue($context, $classIdVal);
        if (null === $classId) {
            return null;
        }
        $jitObject = $context->type->object;
        if (!$jitObject instanceof JitObjectType) {
            return null;
        }

        return $jitObject->classNameForId($classId);
    }

    /** Compile-time {@see __object__} class display name, or null when unknown (#30814). */
    public static function compileTimeObjectClassName(Context $context, Variable $arg): ?string
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
        return self::constantIntFromLlvmValue($context, $classIdVal);
    }

    /** php-llvm Value has no getConstantValue — use LLVMConstIntGetZExtValue (#5974). */
    private static function constantIntFromLlvmValue(Context $context, \PHPLLVM\Value $val): ?int
    {
        if (!isset($val->value)) {
            return null;
        }
        $lib = $context->llvm->lib;
        if (null === $lib->LLVMIsAConstantInt($val->value)) {
            return null;
        }

        return (int) $lib->LLVMConstIntGetZExtValue($val->value);
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\VmArrayColumnArg;
use PHPCompiler\JIT\Builtin\Type\Object_ as JitObjectType;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;

/**
 * array_column() column_key / index_key JIT guards (#5974, php-src ext/standard/array.c).
 */
final class JitArrayColumnArg
{
    public static function strIntNullTypeErrorMessage(
        string $function,
        int $argIndex,
        string $paramName,
        string $given
    ): string {
        return \sprintf(
            '%s(): Argument #%d ($%s) must be of type %s, %s given',
            $function,
            $argIndex + 1,
            $paramName,
            VmArrayColumnArg::TYPE_LABEL,
            $given
        );
    }

    public static function emitStrIntNullTypeErrorAndAbort(
        Context $context,
        string $function,
        int $argIndex,
        string $paramName,
        string $given
    ): void {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise(
            $context,
            self::strIntNullTypeErrorMessage($function, $argIndex, $paramName, $given)
        );
        $context->builder->call($context->lookupFunction('abort'));
    }

    /**
     * Reject enum-case column_key / index_key operands before coercion.
     *
     * @return bool false when compile-time rejection IR was emitted (caller must not continue lowering)
     */
    public static function compileTimeEnumLabel(Context $context, Variable $arg): ?string
    {
        $label = JitOperandTypeLabel::compileTimeEnumClassName($context, $arg);
        if (null !== $label) {
            return $label;
        }
        if (Variable::TYPE_OBJECT === $arg->type) {
            return self::compileTimeObjectEnumLabel($context, $arg);
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            $constantType = self::constantValueBoxType($context, $arg);
            if (VmVariable::TYPE_ENUM_CASE === $constantType) {
                return JitOperandTypeLabel::compileTimeEnumClassName($context, $arg) ?? 'object';
            }
        }

        return null;
    }

    public static function guardStrIntNullOperand(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex,
        string $paramName
    ): bool {
        $enumLabel = self::compileTimeEnumLabel($context, $arg);
        if (null !== $enumLabel) {
            self::emitStrIntNullTypeErrorAndAbort($context, $function, $argIndex, $paramName, $enumLabel);

            return false;
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            return self::guardValueBoxOperand($context, $arg, $function, $argIndex, $paramName);
        }
        if (Variable::TYPE_NATIVE_DOUBLE === $arg->type) {
            self::emitStrIntNullTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'float');

            return false;
        }
        if (Variable::TYPE_NATIVE_BOOL === $arg->type) {
            self::emitStrIntNullTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'bool');

            return false;
        }
        if (Variable::TYPE_OBJECT === $arg->type) {
            self::emitStrIntNullTypeErrorAndAbort(
                $context,
                $function,
                $argIndex,
                $paramName,
                JitOperandTypeLabel::givenLabel($context, $arg)
            );

            return false;
        }

        return true;
    }

    public static function emitRuntimeColumnKeyReject(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex,
        string $paramName
    ): void {
        $enumLabel = self::compileTimeEnumLabel($context, $arg);
        if (null !== $enumLabel) {
            self::emitStrIntNullTypeErrorAndAbort($context, $function, $argIndex, $paramName, $enumLabel);

            return;
        }
        if (Variable::TYPE_VALUE !== $arg->type) {
            self::emitStrIntNullTypeErrorAndAbort(
                $context,
                $function,
                $argIndex,
                $paramName,
                JitOperandTypeLabel::givenLabel($context, $arg)
            );

            return;
        }
        self::emitRuntimeValueBoxReject($context, $arg, $function, $argIndex, $paramName);
    }

    private static function guardValueBoxOperand(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex,
        string $paramName
    ): bool {
        $constantType = self::constantValueBoxType($context, $arg);
        if (null !== $constantType) {
            if (VmVariable::TYPE_ENUM_CASE === $constantType) {
                $enumLabel = JitOperandTypeLabel::compileTimeEnumClassName($context, $arg) ?? 'object';
                self::emitStrIntNullTypeErrorAndAbort($context, $function, $argIndex, $paramName, $enumLabel);

                return false;
            }
            if (VmVariable::TYPE_OBJECT === $constantType) {
                $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
                $obj = $context->builder->call(
                    $context->lookupFunction('__value__readObject'),
                    $valuePtr
                );
                $objVar = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $obj);
                $enumLabel = self::compileTimeObjectEnumLabel($context, $objVar)
                    ?? JitOperandTypeLabel::compileTimeEnumClassName($context, $objVar);
                if (null !== $enumLabel) {
                    self::emitStrIntNullTypeErrorAndAbort($context, $function, $argIndex, $paramName, $enumLabel);

                    return false;
                }
            }
            if (VmVariable::TYPE_STRING === $constantType
                || VmVariable::TYPE_INTEGER === $constantType
                || VmVariable::TYPE_NULL === $constantType) {
                return true;
            }
            self::emitStrIntNullTypeErrorAndAbort(
                $context,
                $function,
                $argIndex,
                $paramName,
                VmArrayColumnArg::vmTypeName($constantType)
            );

            return false;
        }

        self::emitRuntimeValueBoxReject($context, $arg, $function, $argIndex, $paramName);

        return true;
    }

    private static function compileTimeObjectEnumLabel(Context $context, Variable $arg): ?string
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

        return (int) $classIdVal->getConstantValue();
    }

    private static function constantValueBoxType(Context $context, Variable $arg): ?int
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

        return (int) $typeByte->getConstantValue();
    }

    private static function emitRuntimeValueBoxReject(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex,
        string $paramName
    ): void {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $typeKind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $stringTy = $i8->constInt(VmVariable::TYPE_STRING, false);
        $intTy = $i8->constInt(VmVariable::TYPE_INTEGER, false);
        $nullTy = $i8->constInt(VmVariable::TYPE_NULL, false);
        $objectTy = $i8->constInt(VmVariable::TYPE_OBJECT & 0x7f, false);
        $enumCaseTy = $i8->constInt(VmVariable::TYPE_ENUM_CASE, false);

        $okBlock = BasicBlockHelper::append($context, 'array_column_key_ok');
        $rejectBlock = BasicBlockHelper::append($context, 'array_column_key_reject');

        $isString = $context->builder->icmp(Builder::INT_EQ, $typeKind, $stringTy);
        $isInt = $context->builder->icmp(Builder::INT_EQ, $typeKind, $intTy);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $typeKind, $nullTy);
        $allowed = $context->builder->or($isString, $context->builder->or($isInt, $isNull));
        $context->builder->branchIf($allowed, $okBlock, $rejectBlock);

        $context->builder->positionAtEnd($rejectBlock);
        $isObject = $context->builder->icmp(Builder::INT_EQ, $typeKind, $objectTy);
        $isEnumCase = $context->builder->icmp(Builder::INT_EQ, $typeKind, $enumCaseTy);
        $isObjOrEnum = $context->builder->or($isObject, $isEnumCase);
        $objReject = BasicBlockHelper::append($context, 'array_column_key_obj_reject');
        $genericReject = BasicBlockHelper::append($context, 'array_column_key_generic_reject');
        $context->builder->branchIf($isObjOrEnum, $objReject, $genericReject);

        $context->builder->positionAtEnd($objReject);
        self::emitStrIntNullTypeErrorAndAbort(
            $context,
            $function,
            $argIndex,
            $paramName,
            JitOperandTypeLabel::compileTimeEnumClassName($context, $arg)
                ?? JitOperandTypeLabel::givenLabel($context, $arg)
        );

        $context->builder->positionAtEnd($genericReject);
        self::emitStrIntNullTypeErrorAndAbort(
            $context,
            $function,
            $argIndex,
            $paramName,
            JitOperandTypeLabel::givenLabel($context, $arg)
        );

        $context->builder->positionAtEnd($okBlock);
    }
}

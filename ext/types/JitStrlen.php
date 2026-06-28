<?php

declare(strict_types=1);

namespace PHPCompiler\ext\types;

use PHPCompiler\ext\standard\TriggerErrorJitHelper;
use PHPCompiler\ext\standard\VmNullStringParamDeprecation;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for strlen() string operand (php-src ext/standard/string.c, #5119, #10166, #10910). */
final class JitStrlen
{
    public static function lowerLength(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            if ($context->callerStrictTypes) {
                self::emitTypeErrorAndAbort($context, 'null');

                return $context->getTypeFromString('int64')->constInt(0, false);
            }
            self::emitNullStringDeprecation($context);

            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return self::lowerLengthFromValueBox($context, $arg);
        }
        $strPtr = self::lowerStringOperand($context, $arg);
        $doneBlock = BasicBlockHelper::append($context, 'strlen_scalar_done');
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($doneBlock);

        return self::loadStringLength($context, $strPtr);
    }

    public static function lowerStringOperand(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_HASHTABLE === $arg->type) {
            self::emitTypeErrorAndAbort($context, 'array');

            return self::unreachableStringPtr($context);
        }
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            JitStringBuiltinArg::emitObjectTypeErrorReject($context, $arg, 'strlen', 0, 'string');

            return self::unreachableStringPtr($context);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return self::lowerBoxedStringOperand($context, $arg);
        }
        if ($context->callerStrictTypes) {
            JitInternalStrictArg::requireString($context, $arg, 'strlen', 'string', 1);
        }

        return JitStringArg::lower($context, $arg, 'strlen() string');
    }

    public static function lowerBoxedStringOperand(Context $context, JITVariable $arg): Value
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $typeKind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $arrayTy = $i8->constInt(JITVariable::TYPE_HASHTABLE & 0x7f, false);
        $objectTy = $i8->constInt(VmVariable::TYPE_OBJECT, false);
        $enumCaseTy = $i8->constInt(VmVariable::TYPE_ENUM_CASE, false);

        $okBlock = BasicBlockHelper::append($context, 'strlen_str_ok');
        $arrayBlock = BasicBlockHelper::append($context, 'strlen_str_array');
        $objectBlock = BasicBlockHelper::append($context, 'strlen_str_object');
        $strictBlock = BasicBlockHelper::append($context, 'strlen_str_strict');

        $isArray = $context->builder->icmp(Builder::INT_EQ, $typeKind, $arrayTy);
        $isObject = $context->builder->icmp(Builder::INT_EQ, $typeKind, $objectTy);
        $isEnumCase = $context->builder->icmp(Builder::INT_EQ, $typeKind, $enumCaseTy);
        $context->builder->branchIf($isArray, $arrayBlock, $okBlock);

        $context->builder->positionAtEnd($arrayBlock);
        self::emitTypeErrorAndAbort($context, 'array');

        $context->builder->positionAtEnd($okBlock);
        $isObjOrEnum = $context->builder->or($isObject, $isEnumCase);
        $context->builder->branchIf($isObjOrEnum, $objectBlock, $strictBlock);

        $context->builder->positionAtEnd($objectBlock);
        JitStringBuiltinArg::emitRuntimeBoxedRejectForStrlen($context, $valuePtr, $isEnumCase);

        $context->builder->positionAtEnd($strictBlock);
        if ($context->callerStrictTypes) {
            $isString = $context->builder->icmp(
                Builder::INT_EQ,
                $typeKind,
                $i8->constInt(VmVariable::TYPE_STRING, false)
            );
            $coerceBlock = BasicBlockHelper::append($context, 'strlen_str_coerce');
            $strictErrBlock = BasicBlockHelper::append($context, 'strlen_str_strict_err');
            $context->builder->branchIf($isString, $coerceBlock, $strictErrBlock);
            $context->builder->positionAtEnd($strictErrBlock);
            self::emitTypeErrorAndAbort($context, 'mixed');
            $context->builder->positionAtEnd($coerceBlock);
        }

        return JitStringArg::lower($context, $arg, 'strlen() string');
    }

    /** @return Value int64 length */
    private static function lowerLengthFromValueBox(Context $context, JITVariable $arg): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $typeKind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $nullErrBlock = BasicBlockHelper::append($context, 'strlen_null_typeerror');
        $okBlock = BasicBlockHelper::append($context, 'strlen_value_ok');
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $typeKind,
            $i8->constInt(VmVariable::TYPE_NULL, false)
        );
        if ($context->callerStrictTypes) {
            $context->builder->branchIf($isNull, $nullErrBlock, $okBlock);
            $context->builder->positionAtEnd($nullErrBlock);
            self::emitTypeErrorAndAbort($context, 'null');
            $context->builder->positionAtEnd($okBlock);
        } else {
            $coerceFromNullBlock = BasicBlockHelper::append($context, 'strlen_value_null_len');
            $context->builder->branchIf($isNull, $coerceFromNullBlock, $okBlock);
            $context->builder->positionAtEnd($coerceFromNullBlock);
            self::emitNullStringDeprecation($context);

            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        $arrayTy = $i8->constInt(JITVariable::TYPE_HASHTABLE & 0x7f, false);
        $objectTy = $i8->constInt(VmVariable::TYPE_OBJECT, false);
        $enumCaseTy = $i8->constInt(VmVariable::TYPE_ENUM_CASE, false);
        $arrayBlock = BasicBlockHelper::append($context, 'strlen_value_array');
        $objectBlock = BasicBlockHelper::append($context, 'strlen_value_object');
        $coerceBlock = BasicBlockHelper::append($context, 'strlen_value_coerce');
        $checkBlock = BasicBlockHelper::append($context, 'strlen_value_check');
        $isArray = $context->builder->icmp(Builder::INT_EQ, $typeKind, $arrayTy);
        $context->builder->branchIf($isArray, $arrayBlock, $checkBlock);
        $context->builder->positionAtEnd($arrayBlock);
        self::emitTypeErrorAndAbort($context, 'array');
        $context->builder->positionAtEnd($checkBlock);
        $isObject = $context->builder->icmp(Builder::INT_EQ, $typeKind, $objectTy);
        $isEnumCase = $context->builder->icmp(Builder::INT_EQ, $typeKind, $enumCaseTy);
        $isObjOrEnum = $context->builder->or($isObject, $isEnumCase);
        $context->builder->branchIf($isObjOrEnum, $objectBlock, $coerceBlock);
        $context->builder->positionAtEnd($objectBlock);
        JitStringBuiltinArg::emitRuntimeBoxedRejectForStrlen($context, $valuePtr, $isEnumCase);
        $context->builder->positionAtEnd($coerceBlock);
        $argValue = JitStringArg::lower($context, $arg, 'strlen() string');

        return self::loadStringLength($context, $argValue);
    }

    private static function loadStringLength(Context $context, Value $strPtr): Value
    {
        $offset = $context->structFieldMap[$strPtr->typeOf()->getElementType()->getName()]['length'];

        return $context->builder->load(
            $context->builder->structGep($strPtr, $offset)
        );
    }

    private static function compileTimeObjectGivenLabel(Context $context, JITVariable $arg): string
    {
        if (JITVariable::KIND_VALUE !== $arg->kind) {
            return 'object';
        }
        $objMap = $context->structFieldMap['__object__'] ?? null;
        if (null === $objMap || !isset($objMap['class_id'])) {
            return 'object';
        }
        $classIdVal = $context->builder->load(
            $context->builder->structGep($arg->value, $objMap['class_id'])
        );
        if (!method_exists($classIdVal, 'isConstant') || !$classIdVal->isConstant()) {
            return 'object';
        }
        $classId = (int) $classIdVal->getConstantValue();

        return $context->type->object->classNameForId($classId);
    }

    private static function typeErrorMessage(string $given): string
    {
        return sprintf(
            'strlen(): Argument #1 ($string) must be of type string, %s given',
            $given
        );
    }

    public static function emitTypeErrorAndAbort(Context $context, string $given): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $message = self::typeErrorMessage($given);
        if (null !== TryCatchHelper::resolveThrowHandler($context)) {
            TryCatchHelper::emitCatchableClassError($context, 'TypeError', $message);

            return;
        }
        TypeErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function unreachableStringPtr(Context $context): Value
    {
        return $context->getTypeFromString('__string__*')->constNull();
    }

    private static function emitNullStringDeprecation(Context $context): void
    {
        TriggerErrorJitHelper::recordAndMaybePrint(
            ErrorReporter::E_DEPRECATED,
            VmNullStringParamDeprecation::message('strlen', 0, 'string')
        );
    }
}

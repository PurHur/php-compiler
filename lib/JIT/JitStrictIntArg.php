<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** Strict int builtin operands — TypeError on array/object/non-int (issue #4497). */
final class JitStrictIntArg
{
    public static function lower(
        Context $context,
        Variable $arg,
        string $function,
        int $position,
        string $paramName
    ): Value {
        if (($arg->type & Variable::IS_NATIVE_ARRAY) || Variable::TYPE_HASHTABLE === $arg->type) {
            self::emitTypeErrorAndAbort($context, $function, $position, $paramName, 'array');

            return self::unreachableLong($context);
        }
        if (Variable::TYPE_OBJECT === $arg->type) {
            self::emitTypeErrorAndAbort(
                $context,
                $function,
                $position,
                $paramName,
                JitOperandTypeLabel::givenLabel($context, $arg)
            );

            return self::unreachableLong($context);
        }
        if (Variable::TYPE_STRING === $arg->type
            || (Variable::TYPE_VALUE === $arg->type && null !== $arg->compileTimeString)) {
            self::emitTypeErrorAndAbort($context, $function, $position, $paramName, 'string');

            return self::unreachableLong($context);
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            return self::lowerBoxed($context, $arg, $function, $position, $paramName);
        }
        if (Variable::TYPE_NATIVE_LONG !== $arg->type) {
            self::emitTypeErrorAndAbort(
                $context,
                $function,
                $position,
                $paramName,
                JitOperandTypeLabel::givenLabel($context, $arg)
            );

            return self::unreachableLong($context);
        }

        return $context->helper->loadValue($arg);
    }

    public static function lowerLevel(
        Context $context,
        Variable $arg,
        string $function,
        int $position = 2,
        string $paramName = 'level'
    ): Value {
        $level = self::lower($context, $arg, $function, $position, $paramName);
        self::assertLevelRange($context, $level, $function, $position, $paramName);

        return $level;
    }

    private static function lowerBoxed(
        Context $context,
        Variable $arg,
        string $function,
        int $position,
        string $paramName
    ): Value {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $arrayTy = $i8->constInt(VmVariable::TYPE_ARRAY, false);
        $objectTy = $i8->constInt(VmVariable::TYPE_OBJECT, false);
        $enumCaseTy = $i8->constInt(VmVariable::TYPE_ENUM_CASE, false);
        $intTy = $i8->constInt(VmVariable::TYPE_INTEGER, false);

        $okBlock = BasicBlockHelper::append($context, 'jit_strict_int_ok');
        $arrayBlock = BasicBlockHelper::append($context, 'jit_strict_int_array');
        $rejectBlock = BasicBlockHelper::append($context, 'jit_strict_int_reject');
        $coerceBlock = BasicBlockHelper::append($context, 'jit_strict_int_coerce');

        $isArray = $context->builder->icmp(Builder::INT_EQ, $typeByte, $arrayTy);
        $context->builder->branchIf($isArray, $arrayBlock, $okBlock);

        $context->builder->positionAtEnd($arrayBlock);
        self::emitTypeErrorAndAbort($context, $function, $position, $paramName, 'array');

        $context->builder->positionAtEnd($okBlock);
        $isObject = $context->builder->icmp(Builder::INT_EQ, $typeByte, $objectTy);
        $isEnumCase = $context->builder->icmp(Builder::INT_EQ, $typeByte, $enumCaseTy);
        $isObjOrEnum = $context->builder->or($isObject, $isEnumCase);
        $context->builder->branchIf($isObjOrEnum, $rejectBlock, $coerceBlock);

        $context->builder->positionAtEnd($rejectBlock);
        self::emitTypeErrorAndAbort(
            $context,
            $function,
            $position,
            $paramName,
            JitOperandTypeLabel::givenLabel($context, $arg)
        );

        $context->builder->positionAtEnd($coerceBlock);
        $isInt = $context->builder->icmp(Builder::INT_EQ, $typeByte, $intTy);
        $intOkBlock = BasicBlockHelper::append($context, 'jit_strict_int_read');
        $stringErrBlock = BasicBlockHelper::append($context, 'jit_strict_int_string_err');
        $context->builder->branchIf($isInt, $intOkBlock, $stringErrBlock);

        $context->builder->positionAtEnd($stringErrBlock);
        self::emitTypeErrorAndAbort($context, $function, $position, $paramName, 'string');

        $context->builder->positionAtEnd($intOkBlock);

        return $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $valuePtr
        );
    }

    private static function assertLevelRange(
        Context $context,
        Value $level,
        string $function,
        int $position,
        string $paramName
    ): void {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');
        $tooLow = $context->builder->icmp(Builder::INT_SLT, $level, $i64->constInt(-1, true));
        $tooHigh = $context->builder->icmp(Builder::INT_SGT, $level, $i64->constInt(9, false));
        $bad = $context->builder->or($tooLow, $tooHigh);
        $okBlock = BasicBlockHelper::append($context, 'jit_zlib_level_ok');
        $failBlock = BasicBlockHelper::append($context, 'jit_zlib_level_fail');
        $context->builder->branchIf($bad, $failBlock, $okBlock);
        $context->builder->positionAtEnd($failBlock);
        self::emitValueErrorAndAbort(
            $context,
            \sprintf(
                '%s(): Argument #%d ($%s) must be between -1 and 9',
                $function,
                $position,
                $paramName
            )
        );
        $context->builder->positionAtEnd($okBlock);
    }

    private static function emitTypeErrorAndAbort(
        Context $context,
        string $function,
        int $position,
        string $paramName,
        string $given
    ): void {
        $llvmFunc = BasicBlockHelper::parentFunction($context);
        ExceptionBridge::emitTypeErrorAndAbort(
            $context,
            \sprintf(
                '%s(): Argument #%d ($%s) must be of type int, %s given',
                $function,
                $position,
                $paramName,
                $given
            )
        );
        self::positionDeadContinuation($context, $llvmFunc);
    }

    private static function emitValueErrorAndAbort(Context $context, string $message): void
    {
        $llvmFunc = BasicBlockHelper::parentFunction($context);
        if (null !== TryCatchHelper::resolveThrowHandler($context)) {
            TryCatchHelper::emitCatchableClassError($context, 'ValueError', $message);
            self::positionDeadContinuation($context, $llvmFunc);

            return;
        }

        TypeErrorRaise::emitValueError($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
        self::positionDeadContinuation($context, $llvmFunc);
    }

    private static function positionDeadContinuation(Context $context, \PHPLLVM\Value\Function_ $func): void
    {
        $dead = $func->appendBasicBlock('jit_strict_int_err_dead');
        $context->builder->positionAtEnd($dead);
    }

    private static function unreachableLong(Context $context): Value
    {
        return $context->getTypeFromString('int64')->constInt(0, false);
    }
}

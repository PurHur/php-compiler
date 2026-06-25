<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\ClockGettimeJit;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Lower clock_gettime() clock parameter (ClockInterface, #11624).
 */
final class JitClockGettimeArg
{
    public static function lower(Context $context, ?Variable $arg, string $fn): Value
    {
        $i64 = $context->getTypeFromString('int64');
        if (null === $arg) {
            return $i64->constInt(0, false);
        }

        $compileTime = ClockGettimeJit::compileTimeClockId($context, $arg);
        if (null !== $compileTime) {
            return $i64->constInt($compileTime, false);
        }

        if (Variable::TYPE_VALUE === $arg->type) {
            return self::lowerBoxed($context, $arg, $fn);
        }

        self::emitTypeErrorAndAbort($context, $fn, 'mixed');

        return $i64->constInt(0, false);
    }

    private static function lowerBoxed(Context $context, Variable $arg, string $fn): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');

        $enumBlock = BasicBlockHelper::append($context, 'jit_clock_enum');
        $badBlock = BasicBlockHelper::append($context, 'jit_clock_bad');
        $mergeBlock = BasicBlockHelper::append($context, 'jit_clock_merge');
        $isEnumCase = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_ENUM_CASE, false)
        );
        $context->builder->branchIf($isEnumCase, $enumBlock, $badBlock);

        $context->builder->positionAtEnd($enumBlock);
        $clockId = self::lowerClockInterfaceEnumCase($context, $valuePtr, $fn);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($badBlock);
        self::emitTypeErrorAndAbort($context, $fn, 'mixed');
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);

        return $clockId ?? $i64->constInt(0, false);
    }

    private static function lowerClockInterfaceEnumCase(Context $context, Value $valuePtr, string $fn): Value
    {
        $clockInterfaceId = $context->type->object->clockInterfaceEnumClassId();
        if (null === $clockInterfaceId) {
            throw new \LogicException('ClockInterface enum class id missing for JIT (#11624)');
        }

        $map = $context->structFieldMap['__value__'];
        $enumCasePtr = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['value'])
        );
        $enumMap = $context->structFieldMap['__enumcase__'];
        $classId = $context->builder->load(
            $context->builder->structGep($enumCasePtr, $enumMap['classId'])
        );
        $i32 = $context->getTypeFromString('int32');
        $isClock = $context->builder->icmp(
            Builder::INT_EQ,
            $classId,
            $i32->constInt($clockInterfaceId, false)
        );

        $okBlock = BasicBlockHelper::append($context, 'jit_clock_enum_ok');
        $badBlock = BasicBlockHelper::append($context, 'jit_clock_enum_bad');
        $doneBlock = BasicBlockHelper::append($context, 'jit_clock_enum_done');
        $context->builder->branchIf($isClock, $okBlock, $badBlock);

        $context->builder->positionAtEnd($badBlock);
        self::emitTypeErrorAndAbort($context, $fn, 'object');
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $backingPtr = $context->builder->structGep($enumCasePtr, $enumMap['backingValue']);
        $backingValuePtr = $context->builder->load($backingPtr);
        $backingMap = $context->structFieldMap['__value__'];
        $backingLong = $context->builder->load(
            $context->builder->structGep($backingValuePtr, $backingMap['value'])
        );
        $i64 = $context->getTypeFromString('int64');
        $clockId = $backingLong->typeOf() === $i64
            ? $backingLong
            : $context->builder->zExt($backingLong, $i64);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $clockId;
    }

    private static function emitTypeErrorAndAbort(Context $context, string $fn, string $given): void
    {
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitTypeError(
            $context,
            \sprintf('%s(): Argument #1 ($clock) must be of type ClockInterface, %s given', $fn, $given)
        );
    }
}

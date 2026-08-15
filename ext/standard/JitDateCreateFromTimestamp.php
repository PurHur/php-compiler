<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\DateTimeSupport;
use PHPCompiler\VM\NativeDateRangeError;
use PHPLLVM\Value;

/**
 * LLVM lowering for DateTime::createFromTimestamp() / DateTimeImmutable::createFromTimestamp().
 *
 * php-src: ext/date/php_date.c — zim_DateTime_createFromTimestamp / Immutable sibling (#26936, #5973).
 * Thin user-script AOT previously hit ExternalMethod null stub → abort on chained format().
 */
final class JitDateCreateFromTimestamp
{
    public static function invoke(Context $context, bool $immutable, JITVariable ...$args): Value
    {
        $function = $immutable
            ? 'DateTimeImmutable::createFromTimestamp'
            : 'DateTime::createFromTimestamp';
        $argc = \count($args);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects exactly 1 argument, %d given',
                $function,
                $argc
            ));
        }

        $timestamp = self::compileTimeTimestampNumber($context, $args[0], $function);
        if (null === $timestamp) {
            throw new \LogicException(
                $function.'() requires a compile-time int|float $timestamp in this compiler build (#26936)'
            );
        }

        try {
            $parts = DateTimeSupport::splitTimestampNumber($timestamp, $function);
        } catch (NativeDateRangeError $e) {
            // Compile-time NAN/INF/out-of-range — emit catchable DateRangeError IR (#31119).
            ExceptionBridge::emitDateRangeErrorAndAbort($context, $e->getMessage());
            $dead = BasicBlockHelper::append($context, 'create_from_ts_range_dead');
            $context->builder->positionAtEnd($dead);

            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }
        $tzName = VmDate::defaultTimezoneGet();

        return self::materializeDateTimeLike(
            $context,
            $immutable,
            $parts['timestamp'],
            $parts['microsecond'],
            $tzName
        );
    }

    private static function compileTimeTimestampNumber(
        Context $context,
        JITVariable $arg,
        string $function
    ): int|float|null {
        unset($function);
        if (null !== $arg->compileTimeLong && JITVariable::TYPE_NATIVE_DOUBLE !== $arg->type) {
            return (int) $arg->compileTimeLong;
        }
        if (null !== $arg->compileTimeFloat) {
            return (float) $arg->compileTimeFloat;
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type && JITVariable::KIND_VALUE === $arg->kind) {
            $lib = $context->llvm->lib;
            if (null !== $lib->LLVMIsAConstantInt($arg->value->value)) {
                return (int) $lib->LLVMConstIntGetSExtValue($arg->value->value);
            }
        }
        if (JITVariable::TYPE_NATIVE_DOUBLE === $arg->type && JITVariable::KIND_VALUE === $arg->kind) {
            return self::compileTimeDoubleFromValue($context, $arg->value);
        }

        return null;
    }

    private static function compileTimeDoubleFromValue(Context $context, Value $value): ?float
    {
        $lib = $context->llvm->lib;
        $losesInfo = $lib->FFI->new('bool');

        if ($value->isAConstantFP()) {
            return $lib->LLVMConstRealGetDouble($value->value, $losesInfo);
        }

        if ($value->isAConstantExpr()) {
            if ($lib::LLVMFNeg !== $lib->LLVMGetConstOpcode($value->value)) {
                return null;
            }
            $operand = self::compileTimeDoubleFromValue($context, $value->getOperand(0));

            return null === $operand ? null : -$operand;
        }

        if ($value->isAInstruction()) {
            if ($lib::LLVMFNeg !== $lib->LLVMGetInstructionOpcode($value->value)) {
                return null;
            }
            $operand = self::compileTimeDoubleFromValue($context, $value->getOperand(0));

            return null === $operand ? null : -$operand;
        }

        return null;
    }

    private static function materializeDateTimeLike(
        Context $context,
        bool $immutable,
        int $timestamp,
        int $microsecond,
        string $tzName
    ): Value {
        $className = $immutable ? 'DateTimeImmutable' : 'DateTime';
        $objectType = $context->type->object;
        $classId = $objectType->lookup($className);
        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);
        $i64 = $context->getTypeFromString('int64');

        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, $className, DateTimeSupport::TS_PROPERTY),
            new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_LONG,
                JITVariable::KIND_VALUE,
                $i64->constInt($timestamp, false)
            ),
            JITVariable::TYPE_NATIVE_LONG
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, $className, DateTimeSupport::MICROSECOND_PROPERTY),
            new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_LONG,
                JITVariable::KIND_VALUE,
                $i64->constInt($microsecond, false)
            ),
            JITVariable::TYPE_NATIVE_LONG
        );
        $tzVar = new JITVariable(
            $context,
            JITVariable::TYPE_STRING,
            JITVariable::KIND_VALUE,
            $context->builder->load($context->constantStringFromString($tzName))
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, $className, DateTimeSupport::TZ_PROPERTY),
            $tzVar,
            JITVariable::TYPE_STRING
        );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeObject'), $ptr, $obj);

        return $ptr;
    }
}

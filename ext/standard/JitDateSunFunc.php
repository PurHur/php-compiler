<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for date_sunrise()/date_sunset() — compile-time literal baking (#6137).
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(date_sunrise), PHP_FUNCTION(date_sunset).
 */
final class JitDateSunFunc
{
    public static function invoke(
        Context $context,
        bool $isSunset,
        string $function,
        JITVariable ...$args
    ): Value {
        $argc = \count($args);
        if ($argc < 1) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                $function.'() expects at least 1 argument, 0 given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        if ($argc > 6) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                \sprintf('%s() expects at most 6 arguments, %d given', $function, $argc)
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        VmEngineBuiltinDeprecation::emitJitFunction($context, $function);

        $timestamp = self::tryCompileTimeLong($context, $args[0]);
        if (null === $timestamp) {
            throw new \LogicException(
                $function.'() requires compile-time numeric arguments in this compiler build (issue #6137)'
            );
        }

        $returnFormat = VmDate::SUNFUNCS_RET_STRING;
        if ($argc >= 2) {
            $parsedFormat = self::tryCompileTimeLong($context, $args[1]);
            if (null === $parsedFormat) {
                throw new \LogicException(
                    $function.'() requires compile-time numeric arguments in this compiler build (issue #6137)'
                );
            }
            $returnFormat = $parsedFormat;
        }

        $latitude = $argc >= 3 ? self::tryCompileTimeDouble($context, $args[2]) : null;
        $longitude = $argc >= 4 ? self::tryCompileTimeDouble($context, $args[3]) : null;
        $zenith = $argc >= 5 ? self::tryCompileTimeDouble($context, $args[4]) : null;
        $gmtOffset = $argc >= 6 ? self::tryCompileTimeDouble($context, $args[5]) : null;

        if (($argc >= 3 && null === $latitude)
            || ($argc >= 4 && null === $longitude)
            || ($argc >= 5 && null === $zenith)
            || ($argc >= 6 && null === $gmtOffset)) {
            throw new \LogicException(
                $function.'() requires compile-time numeric arguments in this compiler build (issue #6137)'
            );
        }

        $result = VmDateSunNative::sunriseSunset(
            $isSunset,
            $timestamp,
            $returnFormat,
            $latitude,
            $longitude,
            $zenith,
            $gmtOffset,
            $argc
        );

        return self::materializeReturn($context, $result);
    }

    /**
     * @param string|int|float|false $result
     */
    private static function materializeReturn(Context $context, mixed $result): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $i64 = $context->getTypeFromString('int64');
        $dbl = $context->getTypeFromString('double');
        $i1 = $context->getTypeFromString('int1');

        if (false === $result) {
            JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));

            return $ptr;
        }
        if (\is_int($result)) {
            JitValueBox::writeLong($context, $slot, $i64->constInt($result, false));

            return $ptr;
        }
        if (\is_float($result)) {
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $ptr,
                $dbl->constReal($result)
            );

            return $ptr;
        }

        $str = $context->builder->load($context->constantStringFromString((string) $result));
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $str
        );

        return $ptr;
    }

    private static function tryCompileTimeLong(Context $context, JITVariable $var): ?int
    {
        if (JITVariable::TYPE_NATIVE_LONG !== $var->type || JITVariable::KIND_VALUE !== $var->kind) {
            return null;
        }
        $lib = $context->llvm->lib;
        if (null !== $lib->LLVMIsAConstantInt($var->value->value)) {
            return (int) $lib->LLVMConstIntGetZExtValue($var->value->value);
        }

        return null;
    }

    private static function tryCompileTimeDouble(Context $context, JITVariable $var): ?float
    {
        if (null !== $var->compileTimeFloat) {
            return $var->compileTimeFloat;
        }
        if (JITVariable::TYPE_NATIVE_DOUBLE !== $var->type || JITVariable::KIND_VALUE !== $var->kind) {
            return null;
        }

        return self::compileTimeDoubleFromValue($context, $var->value);
    }

    private static function compileTimeDoubleFromValue(Context $context, \PHPLLVM\Value $value): ?float
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
}

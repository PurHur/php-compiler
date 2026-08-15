<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitStrictIntArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\DateTimeSupport;
use PHPLLVM\Value;

/**
 * DateTime(Immutable)::getMicrosecond / setMicrosecond — JIT/AOT (#26938, #7082).
 *
 * php-src: ext/date/php_date.c — zim_DateTime_getMicrosecond / zim_DateTime_setMicrosecond
 * Thin user-script AOT previously hit ExternalMethod null stubs (silent NULL).
 */
final class JitDateMicrosecond
{
    public static function invokeGet(Context $context, bool $immutable, JITVariable ...$args): Value
    {
        $function = $immutable ? 'DateTimeImmutable::getMicrosecond' : 'DateTime::getMicrosecond';
        if ([] === $args) {
            throw new \LogicException($function.'() requires $this');
        }
        $layout = $immutable ? 'DateTimeImmutable' : 'DateTime';
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        $micro = ReflectionSetup::integerPropertyAsI64(
            $context,
            $obj,
            $layout,
            DateTimeSupport::MICROSECOND_PROPERTY
        );
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $slot, $micro);

        return $slot;
    }

    public static function invokeSet(Context $context, bool $immutable, JITVariable ...$args): Value
    {
        $function = $immutable ? 'DateTimeImmutable::setMicrosecond' : 'DateTime::setMicrosecond';
        if (\count($args) < 2) {
            throw new \LogicException($function.'() expects exactly 1 argument');
        }

        $microsecond = self::lowerMicrosecondArg($context, $args[1], $function);
        $layout = $immutable ? 'DateTimeImmutable' : 'DateTime';
        $objectType = $context->type->object;
        $receiver = ReflectionSetup::loadObjectFromArg($context, $args[0]);

        if ($immutable) {
            $classId = $objectType->lookup($layout);
            $target = $objectType->allocate($classId);
            ReflectionSetup::markConstructed($context, $target);
            $ts = $objectType->propertyFetch($receiver, $layout, DateTimeSupport::TS_PROPERTY);
            $tz = $objectType->propertyFetch($receiver, $layout, DateTimeSupport::TZ_PROPERTY);
            $objectType->propertyStore(
                $objectType->propertySlotFor($target, $layout, DateTimeSupport::TS_PROPERTY),
                $ts,
                JITVariable::TYPE_NATIVE_LONG
            );
            $objectType->propertyStore(
                $objectType->propertySlotFor($target, $layout, DateTimeSupport::TZ_PROPERTY),
                $tz,
                JITVariable::TYPE_STRING
            );
            $objectType->propertyStore(
                $objectType->propertySlotFor($target, $layout, DateTimeSupport::MICROSECOND_PROPERTY),
                new JITVariable(
                    $context,
                    JITVariable::TYPE_NATIVE_LONG,
                    JITVariable::KIND_VALUE,
                    $microsecond
                ),
                JITVariable::TYPE_NATIVE_LONG
            );
            $retObj = $target;
        } else {
            $objectType->propertyStore(
                $objectType->propertySlotFor($receiver, $layout, DateTimeSupport::MICROSECOND_PROPERTY),
                new JITVariable(
                    $context,
                    JITVariable::TYPE_NATIVE_LONG,
                    JITVariable::KIND_VALUE,
                    $microsecond
                ),
                JITVariable::TYPE_NATIVE_LONG
            );
            $retObj = $receiver;
        }

        $ret = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $ret),
            $retObj
        );

        return $ret;
    }

    private static function lowerMicrosecondArg(Context $context, JITVariable $arg, string $function): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $className = str_starts_with($function, 'DateTimeImmutable::')
            ? 'DateTimeImmutable'
            : 'DateTime';
        $lit = self::tryCompileTimeLong($context, $arg);
        if (null !== $lit) {
            if ($lit < 0 || $lit > 999999) {
                self::emitDateRangeErrorAndContinue(
                    $context,
                    DateTimeSupport::setMicrosecondRangeErrorMessage($className, $lit)
                );

                return $i64->constInt(0, false);
            }

            return $i64->constInt($lit, false);
        }

        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            $value = $context->helper->loadValue($arg);
            self::emitRangeGuard($context, $value, $className);

            return $value;
        }

        if (JITVariable::TYPE_VALUE === $arg->type) {
            $value = JitStrictIntArg::lower($context, $arg, $function, 1, 'microsecond');
            self::emitRangeGuard($context, $value, $className);

            return $value;
        }

        throw new \LogicException(
            $function.'() requires a compile-time or native-long $microsecond in this compiler build (#26938)'
        );
    }

    private static function emitRangeGuard(Context $context, Value $value, string $className): void
    {
        $i64 = $context->getTypeFromString('int64');
        $okBlock = BasicBlockHelper::append($context, 'set_us_ok');
        $badBlock = BasicBlockHelper::append($context, 'set_us_bad');
        $lt0 = $context->builder->icmp(
            \PHPLLVM\Builder::INT_SLT,
            $value,
            $i64->constInt(0, false)
        );
        $gtMax = $context->builder->icmp(
            \PHPLLVM\Builder::INT_SGT,
            $value,
            $i64->constInt(999999, false)
        );
        $bad = $context->builder->or($lt0, $gtMax);
        $context->builder->branchIf($bad, $badBlock, $okBlock);
        $context->builder->positionAtEnd($badBlock);
        // Runtime i64: class qualifier is known; `, <value> given` is on the compile-time path (#31118).
        ExceptionBridge::emitDateRangeErrorAndAbort(
            $context,
            $className.'::setMicrosecond(): Argument #1 ($microsecond) must be between 0 and 999999'
        );
        BasicBlockHelper::sealOpenBlock($context, $badBlock);
        $context->builder->positionAtEnd($okBlock);
    }

    private static function emitDateRangeErrorAndContinue(Context $context, string $message): void
    {
        ExceptionBridge::emitDateRangeErrorAndAbort($context, $message);
        $dead = BasicBlockHelper::append($context, 'set_us_range_dead');
        $context->builder->positionAtEnd($dead);
    }

    private static function tryCompileTimeLong(Context $context, JITVariable $var): ?int
    {
        if (null !== $var->compileTimeLong) {
            return (int) $var->compileTimeLong;
        }
        if (JITVariable::TYPE_NATIVE_LONG !== $var->type || JITVariable::KIND_VALUE !== $var->kind) {
            return null;
        }
        $lib = $context->llvm->lib;
        if (null !== $lib->LLVMIsAConstantInt($var->value->value)) {
            return (int) $lib->LLVMConstIntGetSExtValue($var->value->value);
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * DateInterval::__construct(string $duration) — JIT/AOT init into allocated $this (#26772).
 *
 * php-src: ext/date/php_date.c — zim_DateInterval___construct
 */
final class JitDateIntervalConstruct
{
    private const CLASS_NAME = 'DateInterval';

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \ArgumentCountError('DateInterval::__construct() expects exactly 1 argument, 0 given');
        }
        // string $duration — null TypeError under caller strict_types (#29828).
        if (JITVariable::TYPE_NULL === $args[1]->type || $args[1]->isNullConstant) {
            JitInternalStrictArg::requireString($context, $args[1], 'DateInterval::__construct', 'duration', 1);
            $lit = '';
        } else {
            $lit = self::compileTimeStringArg($args[1]);
            if (null === $lit) {
                throw new \LogicException(
                    'DateInterval::__construct() requires a compile-time string $duration in this compiler build (#26772)'
                );
            }
        }
        $parsed = VmDateInterval::parseSpec($lit);
        $args[0]->compileTimeDateInterval = $parsed;
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        $objectType = $context->type->object;
        $i64 = $context->getTypeFromString('int64');
        foreach (['y', 'm', 'd', 'h', 'i', 's', 'invert'] as $name) {
            $objectType->propertyStore(
                $objectType->propertySlotFor($obj, self::CLASS_NAME, $name),
                new JITVariable(
                    $context,
                    JITVariable::TYPE_NATIVE_LONG,
                    JITVariable::KIND_VALUE,
                    $i64->constInt($parsed[$name], false)
                ),
                JITVariable::TYPE_NATIVE_LONG
            );
        }
        $fSlot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            JitValueBox::pointer($context, $fSlot),
            $context->constantFromFloat($parsed['f'])
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, self::CLASS_NAME, 'f'),
            new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VARIABLE, $fSlot),
            JITVariable::TYPE_VALUE
        );
        $daysSlot = JitValueBox::alloc($context);
        JitValueBox::writeBool(
            $context,
            $daysSlot,
            $context->getTypeFromString('int32')->constInt(0, false)
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, self::CLASS_NAME, 'days'),
            new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VARIABLE, $daysSlot),
            JITVariable::TYPE_VALUE
        );
        ReflectionSetup::markConstructed($context, $obj);

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }

    private static function compileTimeStringArg(JITVariable $arg): ?string
    {
        $lit = JitStringBuiltinArg::compileTimeLiteral($arg);
        if (null !== $lit) {
            return $lit;
        }

        return $arg->compileTimeString;
    }
}

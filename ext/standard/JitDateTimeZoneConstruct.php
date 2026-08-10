<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\DateTimeSupport;
use PHPCompiler\VM\NativeDateInvalidTimeZoneException;
use PHPLLVM\Value;

/**
 * DateTimeZone::__construct(string $timezone) — JIT/AOT (#26772).
 *
 * php-src: ext/date/php_date.c — zim_DateTimeZone___construct
 */
final class JitDateTimeZoneConstruct
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('DateTimeZone::__construct() called without $this');
        }
        if (\count($args) < 2) {
            throw new \ArgumentCountError('DateTimeZone::__construct() expects exactly 1 argument');
        }
        $name = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $name) {
            throw new \LogicException(
                'DateTimeZone::__construct() requires a compile-time string $timezone in this compiler build (#26772)'
            );
        }
        $vmCtx = $context->runtime->vmContext;
        if (null === $vmCtx) {
            throw new \LogicException('DateTimeZone::__construct() requires VM context at JIT compile time (#26772)');
        }
        try {
            DateTimeSupport::newDateTimeZoneVariable($vmCtx, $name);
        } catch (NativeDateInvalidTimeZoneException $e) {
            throw $e;
        }

        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        $objectType = $context->type->object;
        $tzVar = new JITVariable(
            $context,
            JITVariable::TYPE_STRING,
            JITVariable::KIND_VALUE,
            $context->builder->load($context->constantStringFromString($name))
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, 'DateTimeZone', DateTimeSupport::TZ_NAME_PROPERTY),
            $tzVar,
            JITVariable::TYPE_STRING
        );
        ReflectionSetup::markConstructed($context, $obj);
        // Zone id on dedicated field — compileTimeString stays class name from New_ (#29732).
        $args[0]->compileTimeTimezoneName = $name;
        $args[0]->compileTimeString = $name;

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }
}

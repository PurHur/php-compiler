<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\DateTimeSupport;
use PHPLLVM\Value;

/**
 * LLVM lowering for timezone_name_get() / DateTimeZone::getName() (#11746, #27307).
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(timezone_name_get) / zim_DateTimeZone_getName
 *
 * Compile-time materialize of the zone name (DateTimeZone::__construct leaves it on $this),
 * peer {@see JitTimezoneTransitionsGet} (#26799).
 */
final class JitTimezoneNameGet
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \ArgumentCountError(
                \sprintf('timezone_name_get() expects exactly 1 argument, %d given', \count($args))
            );
        }

        return self::lower($context, 'timezone_name_get', $args[0]);
    }

    /** DateTimeZone::getName($this) — same ABI as procedural (#27307). */
    public static function invokeMethod(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \ArgumentCountError(
                \sprintf('DateTimeZone::getName() expects exactly 0 arguments, %d given', max(0, \count($args) - 1))
            );
        }

        return self::lower($context, 'DateTimeZone::getName', $args[0]);
    }

    private static function lower(Context $context, string $function, JITVariable $zoneArg): Value
    {
        $zoneName = self::tryCompileTimeZoneName($context, $zoneArg);
        if (null === $zoneName) {
            throw new \LogicException(
                $function.'() requires a compile-time DateTimeZone name in this compiler build (#27307)'
            );
        }

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $context->builder->load($context->constantStringFromString($zoneName))
        );
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);

        return $ptr;
    }

    private static function tryCompileTimeZoneName(Context $context, JITVariable $arg): ?string
    {
        // DateTimeZone::__construct leaves the zone name on $this (#26772 / #26799 / #27307).
        if (null !== $arg->compileTimeString && '' !== $arg->compileTimeString) {
            return $arg->compileTimeString;
        }

        $literal = JitStringBuiltinArg::compileTimeLiteral($arg);
        if (null !== $literal && '' !== $literal) {
            return $literal;
        }

        if (JITVariable::TYPE_OBJECT === $arg->type) {
            /** @var \PHPCompiler\JIT\Builtin\Type\Object_ $object */
            $object = $context->type->object;
            $prop = $object->propertyFetch($arg->value, 'DateTimeZone', DateTimeSupport::TZ_NAME_PROPERTY);
            if (JITVariable::TYPE_STRING === $prop->type && null !== ($prop->compileTimeString ?? null)) {
                return $prop->compileTimeString;
            }
        }

        return null;
    }
}

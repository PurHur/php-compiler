<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\TimezoneOffsetRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for date_offset_get() (#11876, ext/date/php_date.c). */
final class JitDateOffsetGet
{
    private const TYPE_ERROR =
        'date_offset_get(): Argument #1 ($object) must be of type DateTimeInterface, %s given';

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \ArgumentCountError(
                \sprintf('date_offset_get() expects exactly 1 argument, %d given', \count($args))
            );
        }

        TimezoneOffsetRuntime::ensureLinked($context);

        /** @var \PHPCompiler\JIT\Builtin\Type\Object_ $object */
        $object = $context->type->object;
        $dtObj = JitTimezoneProceduralArg::requireDateTimeInterfaceObject($context, $args[0], self::TYPE_ERROR);
        $zoneName = JitTimezoneProceduralArg::readDateTimeTimezoneNameProp($context, $object, $dtObj);
        $timestamp = JitTimezoneProceduralArg::readTimestampProp($context, $object, $dtObj);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__phpc_timezone_offset_seconds'),
            $zoneName,
            $timestamp,
            $ptr
        );

        return $ptr;
    }
}

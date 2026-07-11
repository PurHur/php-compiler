<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\DateTimeSupport;
use PHPLLVM\Value;

/** LLVM lowering for timezone_name_get() (#11746, ext/date/php_date.c). */
final class JitTimezoneNameGet
{
    private const TYPE_ERROR =
        'timezone_name_get(): Argument #1 ($object) must be of type DateTimeZone, %s given';

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \ArgumentCountError(
                \sprintf('timezone_name_get() expects exactly 1 argument, %d given', \count($args))
            );
        }

        /** @var \PHPCompiler\JIT\Builtin\Type\Object_ $object */
        $object = $context->type->object;
        $zoneObj = JitTimezoneProceduralArg::requireDateTimeZoneObject($context, $args[0], self::TYPE_ERROR);
        $zoneName = JitTimezoneProceduralArg::readStringProp(
            $context,
            $object,
            $zoneObj,
            'DateTimeZone',
            DateTimeSupport::TZ_NAME_PROPERTY
        );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $zoneName);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);

        return $ptr;
    }
}

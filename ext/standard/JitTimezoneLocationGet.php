<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\TimezoneLocationRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\DateTimeSupport;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for timezone_location_get() (#6041 phase 2, ext/date/php_date.c). */
final class JitTimezoneLocationGet
{
    private const TYPE_ERROR =
        'timezone_location_get(): Argument #1 ($object) must be of type DateTimeZone, %s given';

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \ArgumentCountError(
                \sprintf('timezone_location_get() expects exactly 1 argument, %d given', \count($args))
            );
        }

        TimezoneLocationRuntime::ensureLinked($context);

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
        $ht = $context->builder->call(
            $context->lookupFunction('__phpc_timezone_location_ht'),
            $zoneName
        );
        $nullHt = $ht->typeOf()->constNull();
        $isNull = $context->builder->icmp(Builder::INT_EQ, $ht, $nullHt);
        $falseBb = \PHPCompiler\JIT\BasicBlockHelper::append($context, 'tzloc_ret_false');
        $okBb = \PHPCompiler\JIT\BasicBlockHelper::append($context, 'tzloc_ret_ok');
        $doneBb = \PHPCompiler\JIT\BasicBlockHelper::append($context, 'tzloc_ret_done');
        $context->builder->branchIf($isNull, $falseBb, $okBb);

        $context->builder->positionAtEnd($falseBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $ptr,
            $context->getTypeFromString('int32')->constInt(0, false)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $ht
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $ptr;
    }
}

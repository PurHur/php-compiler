<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\DateTimeSupport;
use PHPLLVM\Value;

/**
 * DateTime::setTimezone() / DateTimeImmutable::setTimezone() — JIT (#3072, #22824).
 *
 * php-src: ext/date/php_date.c — zim_date_timezone_set / zim_DateTimeImmutable_setTimezone
 *
 * Mutable: in-place TZ property write. Immutable: cloneObject + TZ write (MCJIT / full-init).
 * Thin user-script AOT skips registering the immutable proxy — Object_::cloneObject loses the
 * insert-block parent there (same as PHP `clone` on DateTimeImmutable under AOT).
 */
final class DateTimeSetTimezone implements Call
{
    private const LAYOUT = 'DateTime';

    public function __construct(
        private readonly bool $immutable
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DateTime::setTimezone() requires $this and a DateTimeZone argument');
        }

        $objectType = $context->type->object;
        $receiver = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        $timezone = ReflectionSetup::loadObjectFromArg($context, $args[1]);
        $tzNameVar = $objectType->propertyFetch(
            $timezone,
            'DateTimeZone',
            DateTimeSupport::TZ_NAME_PROPERTY
        );

        if ($this->immutable) {
            $target = $objectType->cloneObject($receiver);
            ReflectionSetup::markConstructed($context, $target);
            $objectType->propertyStore(
                $objectType->propertySlotFor($target, self::LAYOUT, DateTimeSupport::TZ_PROPERTY),
                $tzNameVar,
                Variable::TYPE_STRING
            );
            $retObj = $target;
        } else {
            $objectType->propertyStore(
                $objectType->propertySlotFor($receiver, self::LAYOUT, DateTimeSupport::TZ_PROPERTY),
                $tzNameVar,
                Variable::TYPE_STRING
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
}

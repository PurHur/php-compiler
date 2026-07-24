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
 * DateTime::setTimezone() / DateTimeImmutable::setTimezone() — JIT/AOT (#3072, #22824).
 *
 * php-src: ext/date/php_date.c — zim_date_timezone_set / zim_DateTimeImmutable_setTimezone
 *
 * Mutable: in-place TZ property write (thin user-script AOT OK).
 * Immutable: allocate + copy props (MCJIT) — not cloneObject. Thin user-script AOT still
 * cannot allocate DateTimeImmutable (insert-block / NestedJIT ensureLinked gap); Context
 * registers the immutable proxy only when UserScriptAotEnv is inactive.
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
            $layout = 'DateTimeImmutable';
            $classId = $objectType->lookup($layout);
            $target = $objectType->allocate($classId);
            ReflectionSetup::markConstructed($context, $target);
            foreach ([DateTimeSupport::TS_PROPERTY, DateTimeSupport::MICROSECOND_PROPERTY] as $prop) {
                $val = $objectType->propertyFetch($receiver, $layout, $prop);
                $objectType->propertyStore(
                    $objectType->propertySlotFor($target, $layout, $prop),
                    $val,
                    Variable::TYPE_NATIVE_LONG
                );
            }
            // Wall-clock instant unchanged; store the new zone name (#22824).
            $objectType->propertyStore(
                $objectType->propertySlotFor($target, $layout, DateTimeSupport::TZ_PROPERTY),
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

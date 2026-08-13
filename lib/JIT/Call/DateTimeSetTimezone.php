<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\standard\JitDateGetLastErrors;
use PHPCompiler\ext\standard\JitDateMutation;
use PHPCompiler\ext\standard\JitDateOffsetGet;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
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
        $function = $this->immutable ? 'DateTimeImmutable::setTimezone' : 'DateTime::setTimezone';
        $argc = \count($args);
        // User arity excludes $this (#30834).
        if (2 !== $argc) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf('%s() expects exactly 1 argument, %d given', $function, max(0, $argc - 1))
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'datetime_settimezone_argc_cont');
            $ret = JitValueBox::alloc($context);
            $context->builder->call(
                $context->lookupFunction('__value__writeNull'),
                JitValueBox::pointer($context, $ret)
            );

            return $ret;
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

/**
 * DateTime::getTimestamp() / DateTimeImmutable::getTimestamp() — JIT/AOT (#30745).
 *
 * Defined here because DateTimeSetTimezone is always registered (all profiles).
 * SSOT: {@see \PHPCompiler\ext\standard\JitDateMutation::invokeTimestampObjectGet}
 */
final class DateTimeGetTimestamp implements Call
{
    public function __construct(
        private readonly bool $immutable = false,
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        return JitDateMutation::invokeTimestampObjectGet(
            $context,
            $this->immutable,
            ...$args
        );
    }
}

/**
 * DateTime::setTimestamp() / DateTimeImmutable::setTimestamp() — JIT/AOT (#30745).
 *
 * SSOT: {@see \PHPCompiler\ext\standard\JitDateMutation::invokeTimestampObjectSet}
 * php-src stub: setTimestamp(int $timestamp): static
 */
final class DateTimeSetTimestamp implements Call
{
    /** Qualified name for BuiltinParamNames / named-arg resolve. */
    public string $name;

    /** @var list<string> php-src ext/date/php_date.stub.php */
    public array $paramNames = ['timestamp'];

    public function __construct(
        private readonly bool $immutable = false,
    ) {
        $this->name = $immutable
            ? 'DateTimeImmutable::setTimestamp'
            : 'DateTime::setTimestamp';
    }

    public function call(Context $context, Variable ...$args): Value
    {
        return JitDateMutation::invokeTimestampObjectSet(
            $context,
            $this->immutable,
            ...$args
        );
    }
}

/**
 * DateTime::getTimezone() / DateTimeImmutable::getTimezone() — JIT/AOT (#30746).
 *
 * Defined here because DateTimeSetTimezone is always registered (all profiles).
 * SSOT: {@see \PHPCompiler\ext\standard\JitDateMutation::invokeTimezoneObjectGet}
 */
final class DateTimeGetTimezone implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitDateMutation::invokeTimezoneObjectGet($context, ...$args);
    }
}

/**
 * DateTime::setDate() / DateTimeImmutable::setDate() — JIT/AOT (#30747).
 *
 * Defined here because DateTimeSetTimezone is always registered (all profiles).
 * SSOT: {@see \PHPCompiler\ext\standard\JitDateMutation::invokeObjectDateSet}
 * php-src stub: setDate(int $year, int $month, int $day): static
 */
final class DateTimeSetDate implements Call
{
    /** Qualified name for BuiltinParamNames / named-arg resolve. */
    public string $name;

    /** @var list<string> php-src ext/date/php_date.stub.php */
    public array $paramNames = ['year', 'month', 'day'];

    public function __construct(
        private readonly bool $immutable = false,
    ) {
        $this->name = $immutable ? 'DateTimeImmutable::setDate' : 'DateTime::setDate';
    }

    public function call(Context $context, Variable ...$args): Value
    {
        return JitDateMutation::invokeObjectDateSet($context, $this->immutable, ...$args);
    }
}

/**
 * DateTime::setTime() / DateTimeImmutable::setTime() — JIT/AOT (#30747).
 *
 * SSOT: {@see \PHPCompiler\ext\standard\JitDateMutation::invokeObjectTimeSet}
 * php-src stub: setTime(int $hour, int $minute, int $second = 0, int $microsecond = 0): static
 */
final class DateTimeSetTime implements Call
{
    /** Qualified name for BuiltinParamNames / named-arg resolve. */
    public string $name;

    /** @var list<string> php-src ext/date/php_date.stub.php */
    public array $paramNames = ['hour', 'minute', 'second=', 'microsecond='];

    public function __construct(
        private readonly bool $immutable = false,
    ) {
        $this->name = $immutable ? 'DateTimeImmutable::setTime' : 'DateTime::setTime';
    }

    public function call(Context $context, Variable ...$args): Value
    {
        return JitDateMutation::invokeObjectTimeSet($context, $this->immutable, ...$args);
    }
}

/**
 * DateTime::getOffset() / DateTimeImmutable::getOffset() — JIT/AOT (#30761).
 *
 * Defined here because DateTimeSetTimezone is always registered (all profiles).
 * SSOT: {@see \PHPCompiler\ext\standard\JitDateOffsetGet::invokeMethod}
 * php-src: ext/date/php_date.c — zim_DateTime_getOffset / zim_DateTimeImmutable_getOffset
 */
final class DateTimeGetOffset implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitDateOffsetGet::invokeMethod($context, ...$args);
    }
}

/**
 * DateTime::setISODate() / DateTimeImmutable::setISODate() — JIT/AOT (#30748).
 *
 * Defined here because DateTimeSetTimezone is always registered (all profiles).
 * SSOT: {@see \PHPCompiler\ext\standard\JitDateMutation::invokeObjectISODateSet}
 * php-src stub: setISODate(int $year, int $week, int $dayOfWeek = 1): static
 */
final class DateTimeSetISODate implements Call
{
    /** Qualified name for BuiltinParamNames / named-arg resolve. */
    public string $name;

    /** @var list<string> php-src ext/date/php_date.stub.php */
    public array $paramNames = ['year', 'week', 'dayOfWeek='];

    public function __construct(
        private readonly bool $immutable = false,
    ) {
        $this->name = $immutable ? 'DateTimeImmutable::setISODate' : 'DateTime::setISODate';
    }

    public function call(Context $context, Variable ...$args): Value
    {
        return JitDateMutation::invokeObjectISODateSet($context, $this->immutable, ...$args);
    }
}

/**
 * DateTime::getLastErrors() / DateTimeImmutable::getLastErrors() — JIT/AOT (#30749).
 *
 * Defined here because DateTimeSetTimezone is always registered (all profiles).
 * SSOT: {@see \PHPCompiler\ext\standard\JitDateGetLastErrors::invokeMethod}
 * php-src stub: getLastErrors(): array|false
 */
final class DateTimeGetLastErrors implements Call
{
    /** Qualified name for BuiltinParamNames / named-arg resolve. */
    public string $name;

    /** @var list<string> */
    public array $paramNames = [];

    /** Static method — no implicit $this. */
    public int $namedArgsReceiverPrefix = 0;

    public function __construct(
        private readonly bool $immutable = false,
    ) {
        $this->name = $immutable ? 'DateTimeImmutable::getLastErrors' : 'DateTime::getLastErrors';
    }

    public function call(Context $context, Variable ...$args): Value
    {
        return JitDateGetLastErrors::invokeMethod($context, $this->name, ...$args);
    }
}

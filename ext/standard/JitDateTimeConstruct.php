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
use PHPCompiler\VM\NativeDateMalformedStringException;
use PHPLLVM\Value;

/**
 * DateTime / DateTimeImmutable::__construct() — JIT/AOT init into allocated $this (#26772).
 *
 * php-src: ext/date/php_date.c — zim_DateTime___construct / zim_DateTimeImmutable___construct
 * Thin user-script AOT previously skipped constructors (no markHasConstructor) → null props.
 */
final class JitDateTimeConstruct
{
    public static function invoke(Context $context, bool $immutable, JITVariable ...$args): Value
    {
        $function = $immutable ? 'DateTimeImmutable::__construct' : 'DateTime::__construct';
        if ([] === $args) {
            throw new \LogicException($function.'() called without $this');
        }
        $argc = \count($args);
        if ($argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects at most 2 arguments, %d given',
                $function,
                $argc - 1
            ));
        }

        $timeLit = 'now';
        if ($argc >= 2) {
            if (JITVariable::TYPE_NULL === $args[1]->type || $args[1]->isNullConstant) {
                $timeLit = VmDateTimeCreateArg::jitNullDatetimeLiteral(
                    $context,
                    $args[1],
                    $function,
                    0,
                    'datetime'
                );
            } else {
                $lit = self::compileTimeStringArg($args[1]);
                if (null === $lit) {
                    throw new \LogicException(
                        $function.'() requires a compile-time string $datetime in this compiler build (#26772)'
                    );
                }
                $timeLit = $lit;
            }
        }
        if ('' === $timeLit) {
            $timeLit = 'now';
        }

        $tzLit = null;
        if ($argc >= 3) {
            if (JITVariable::TYPE_NULL !== $args[2]->type && !$args[2]->isNullConstant) {
                $tzLit = self::compileTimeTimezoneName($context, $args[2], $function);
                if (null === $tzLit) {
                    throw new \LogicException(
                        $function.'() timezone must be a compile-time DateTimeZone/string in this build (#26772)'
                    );
                }
            }
        }

        $parsed = self::parseAtCompileTime($context, $timeLit, $tzLit, $immutable, $function);
        $className = $immutable ? 'DateTimeImmutable' : 'DateTime';
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        self::storeParsedInto($context, $obj, $className, $parsed);
        ReflectionSetup::markConstructed($context, $obj);
        // Foreach DatePeriod snapshot (#26772) — stamp compile-time instant on $this.
        $args[0]->compileTimeLong = $parsed['timestamp'];
        $args[0]->compileTimeString = $parsed['timezone'];

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }

    /**
     * @return array{timestamp: int, microsecond: int, timezone: string}
     */
    private static function parseAtCompileTime(
        Context $context,
        string $time,
        ?string $tzName,
        bool $immutable,
        string $function
    ): array {
        $vmCtx = $context->runtime->vmContext;
        if (null === $vmCtx) {
            throw new \LogicException($function.'() requires VM context at JIT compile time (#26772)');
        }
        $timezone = null;
        if (null !== $tzName && '' !== $tzName) {
            try {
                $zoneVar = DateTimeSupport::newDateTimeZoneVariable($vmCtx, $tzName);
                $timezone = $zoneVar->toObject();
            } catch (NativeDateInvalidTimeZoneException $e) {
                throw $e;
            }
        }
        $created = $immutable
            ? DateTimeSupport::tryNewDateTimeImmutableVariable($vmCtx, $time, $timezone)
            : DateTimeSupport::tryNewDateTimeVariable($vmCtx, $time, $timezone);
        if (null === $created) {
            throw new NativeDateMalformedStringException(
                'Failed to parse time string ('.$time.') at position 0 ('.($time[0] ?? '').'): Unexpected character'
            );
        }
        $obj = $created->toObject();

        return [
            'timestamp' => $obj->getProperty(DateTimeSupport::TS_PROPERTY)->resolveIndirect()->toInt(),
            'microsecond' => $obj->getProperty(DateTimeSupport::MICROSECOND_PROPERTY)->resolveIndirect()->toInt(),
            'timezone' => $obj->getProperty(DateTimeSupport::TZ_PROPERTY)->resolveIndirect()->toString(),
        ];
    }

    /**
     * @param array{timestamp: int, microsecond: int, timezone: string} $parsed
     */
    private static function storeParsedInto(
        Context $context,
        Value $obj,
        string $className,
        array $parsed
    ): void {
        $objectType = $context->type->object;
        $i64 = $context->getTypeFromString('int64');
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, $className, DateTimeSupport::TS_PROPERTY),
            new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_LONG,
                JITVariable::KIND_VALUE,
                $i64->constInt($parsed['timestamp'], false)
            ),
            JITVariable::TYPE_NATIVE_LONG
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, $className, DateTimeSupport::MICROSECOND_PROPERTY),
            new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_LONG,
                JITVariable::KIND_VALUE,
                $i64->constInt($parsed['microsecond'], false)
            ),
            JITVariable::TYPE_NATIVE_LONG
        );
        $tzVar = new JITVariable(
            $context,
            JITVariable::TYPE_STRING,
            JITVariable::KIND_VALUE,
            $context->builder->load($context->constantStringFromString($parsed['timezone']))
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, $className, DateTimeSupport::TZ_PROPERTY),
            $tzVar,
            JITVariable::TYPE_STRING
        );
    }

    private static function compileTimeStringArg(JITVariable $arg): ?string
    {
        $lit = JitStringBuiltinArg::compileTimeLiteral($arg);
        if (null !== $lit) {
            return $lit;
        }

        return $arg->compileTimeString;
    }

    private static function compileTimeTimezoneName(
        Context $context,
        JITVariable $arg,
        string $function
    ): ?string {
        unset($function, $context);
        // Dedicated stamp from DateTimeZone::__construct (#29732 / peer #26772).
        if (null !== $arg->compileTimeTimezoneName && '' !== $arg->compileTimeTimezoneName) {
            return $arg->compileTimeTimezoneName;
        }
        // Legacy stamp; ignore New_ class-name collision on compileTimeString.
        if (
            null !== $arg->compileTimeString
            && '' !== $arg->compileTimeString
            && 0 !== strcasecmp($arg->compileTimeString, 'DateTimeZone')
        ) {
            return $arg->compileTimeString;
        }
        $lit = self::compileTimeStringArg($arg);
        if (null !== $lit) {
            return $lit;
        }

        return null;
    }
}

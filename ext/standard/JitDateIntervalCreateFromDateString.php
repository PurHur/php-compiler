<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\DateIntervalSupport;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Value;

/**
 * LLVM lowering for date_interval_create_from_date_string() (#4606 phase 2, ext/date/php_date.c).
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(date_interval_create_from_date_string)
 */
final class JitDateIntervalCreateFromDateString
{
    private const CLASS_NAME = 'DateInterval';

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        return self::invokeNamed($context, 'date_interval_create_from_date_string', ...$args);
    }

    /** Shared bake path for procedural + DateInterval::createFromDateString (#29843). */
    public static function invokeNamed(Context $context, string $function, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException(
                $function.'() expects exactly 1 argument in this compiler build'
            );
        }

        // string $datetime — null TypeError under caller strict_types (#29843).
        if (JITVariable::TYPE_NULL === $args[0]->type || $args[0]->isNullConstant) {
            JitInternalStrictArg::requireString($context, $args[0], $function, 'datetime', 1);
            $lit = '';
        } else {
            $lit = self::compileTimeStringArg($args[0]);
            if (null === $lit) {
                throw new \LogicException(
                    $function.'() requires compile-time string operands in this compiler build (issue #4606)'
                );
            }
        }

        $warning = null;
        $parsed = VmDateInterval::parseFromDateString($lit, $warning);
        if (null === $parsed) {
            return self::emitParseFailure($context, $function, (string) $warning);
        }

        return self::materializeDateInterval($context, $lit, $parsed);
    }

    private static function compileTimeStringArg(JITVariable $arg): ?string
    {
        $lit = JitStringBuiltinArg::compileTimeLiteral($arg);
        if (null !== $lit) {
            return $lit;
        }

        return $arg->compileTimeString;
    }

    private static function emitParseFailure(Context $context, string $function, string $warning): Value
    {
        self::emitParseWarning($context, $function, $warning);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeBool($context, $slot, $context->getTypeFromString('int1')->constInt(0, false));

        return $ptr;
    }

    private static function emitParseWarning(Context $context, string $function, string $warning): void
    {
        $msg = $function.'(): '.$warning;
        $msgStr = $context->builder->pointerCast(
            $context->constantFromString($msg),
            $context->getTypeFromString('int8*')
        );
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $emptyFile = $context->builder->pointerCast(
            $context->constantFromString(''),
            $context->getTypeFromString('int8*')
        );
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgStr,
            $sizeT->constInt(\strlen($msg), false),
            $i32->constInt(ErrorReporter::E_WARNING, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
    }

    /**
     * @param array{y: int, m: int, d: int, h: int, i: int, s: int, f: float, invert: int} $parsed
     */
    private static function materializeDateInterval(Context $context, string $dateString, array $parsed): Value
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_NAME);
        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);
        $i64 = $context->getTypeFromString('int64');
        $dbl = $context->getTypeFromString('double');
        $i1 = $context->getTypeFromString('int1');

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
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, self::CLASS_NAME, 'f'),
            new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_DOUBLE,
                JITVariable::KIND_VALUE,
                $dbl->constReal($parsed['f'])
            ),
            JITVariable::TYPE_NATIVE_DOUBLE
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, self::CLASS_NAME, 'days'),
            new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_BOOL,
                JITVariable::KIND_VALUE,
                $i1->constInt(0, false)
            ),
            JITVariable::TYPE_NATIVE_BOOL
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, self::CLASS_NAME, DateIntervalSupport::FROM_STRING_STORAGE),
            new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_BOOL,
                JITVariable::KIND_VALUE,
                $i1->constInt(1, false)
            ),
            JITVariable::TYPE_NATIVE_BOOL
        );
        $dateStringVar = new JITVariable(
            $context,
            JITVariable::TYPE_STRING,
            JITVariable::KIND_VALUE,
            $context->constantFromString($dateString)
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, self::CLASS_NAME, DateIntervalSupport::DATE_STRING_STORAGE),
            $dateStringVar,
            JITVariable::TYPE_STRING
        );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeObject'), $ptr, $obj);

        return $ptr;
    }
}

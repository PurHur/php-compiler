<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM implementation of __compiler_checkdate — Gregorian calendar validation (#3292).
 *
 * Mirrors ext/standard/VmDate::checkdate(). php-src: ext/standard/datetime.c PHP_FUNCTION(checkdate).
 */
final class CheckdateRuntime
{
    /** @var array<int, int> */
    private const MONTH_DAYS = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_checkdate');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $ft = $context->context->functionType($i1, false, $i64, $i64, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction('__compiler_checkdate', $ft);
        self::implementCheckdate($context, $fn);
        self::registerLinkedRuntime($context);
    }

    private static function implementCheckdate(Context $context, Value $fn): void
    {
        $entry = $fn->appendBasicBlock('checkdate_entry');
        $context->builder->positionAtEnd($entry);

        $month = $fn->getParam(0);
        $day = $fn->getParam(1);
        $year = $fn->getParam(2);
        $i64 = $context->getTypeFromString('int64');
        $one = $i64->constInt(1, false);
        $twelve = $i64->constInt(12, false);
        $maxYear = $i64->constInt(32767, false);

        $yearLow = $context->builder->icmp(Builder::INT_SGE, $year, $one);
        $yearHigh = $context->builder->icmp(Builder::INT_SLE, $year, $maxYear);
        $yearOk = $context->builder->and($yearLow, $yearHigh);

        $monthLow = $context->builder->icmp(Builder::INT_SGE, $month, $one);
        $monthHigh = $context->builder->icmp(Builder::INT_SLE, $month, $twelve);
        $monthOk = $context->builder->and($monthLow, $monthHigh);

        $dim = self::daysInMonth($context, $year, $month);
        $dayLow = $context->builder->icmp(Builder::INT_SGE, $day, $one);
        $dayHigh = $context->builder->icmp(Builder::INT_SLE, $day, $dim);
        $dayOk = $context->builder->and($dayLow, $dayHigh);

        $valid = $context->builder->and(
            $context->builder->and($yearOk, $monthOk),
            $dayOk
        );

        $context->builder->returnValue($valid);
    }

    private static function daysInMonth(Context $context, Value $year, Value $month): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $dim = $i64->constInt(self::MONTH_DAYS[11], false);
        for ($m = 12; $m >= 1; --$m) {
            $isMonth = $context->builder->icmp(
                Builder::INT_EQ,
                $month,
                $i64->constInt($m, false)
            );
            $dim = $context->builder->select(
                $isMonth,
                $i64->constInt(self::MONTH_DAYS[$m - 1], false),
                $dim
            );
        }

        $isFeb = $context->builder->icmp(
            Builder::INT_EQ,
            $month,
            $i64->constInt(2, false)
        );
        $isLeap = self::isLeapYear($context, $year);
        $febExtra = $context->builder->select(
            $isLeap,
            $i64->constInt(1, false),
            $i64->constInt(0, false)
        );
        $febDim = $context->builder->add($dim, $febExtra);

        return $context->builder->select($isFeb, $febDim, $dim);
    }

    private static function isLeapYear(Context $context, Value $year): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $four = $i64->constInt(4, false);
        $hundred = $i64->constInt(100, false);
        $fourHundred = $i64->constInt(400, false);

        $mod4 = $context->builder->signedRem($year, $four);
        $mod100 = $context->builder->signedRem($year, $hundred);
        $mod400 = $context->builder->signedRem($year, $fourHundred);

        $divBy4 = $context->builder->icmp(Builder::INT_EQ, $mod4, $zero);
        $notDiv100 = $context->builder->icmp(Builder::INT_NE, $mod100, $zero);
        $divBy400 = $context->builder->icmp(Builder::INT_EQ, $mod400, $zero);

        return $context->builder->and(
            $divBy4,
            $context->builder->or($notDiv100, $divBy400)
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction('__compiler_checkdate');
        if (null === $fn) {
            throw new \LogicException('__compiler_checkdate missing after CheckdateRuntime LLVM implement');
        }
        $context->registerFunction('__compiler_checkdate', $fn);
    }
}

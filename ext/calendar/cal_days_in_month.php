<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * cal_days_in_month() — Gregorian/Julian month lengths (php-src ext/calendar/calendar.c; #7223).
 */
final class cal_days_in_month extends Internal
{
    public function __construct()
    {
        parent::__construct('cal_days_in_month');
    }

    public function execute(Frame $frame): void
    {
        if (3 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'cal_days_in_month() expects exactly 3 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        [$calendar, $month, $year] = self::parseIntArgs($frame, 'cal_days_in_month');
        self::assertValidCalendarId($calendar);
        self::assertMonthYear($month, $year);
        $frame->returnVar->int(VmCalendar::calDaysInMonth($calendar, $month, $year));
    }

    private static function assertValidCalendarId(int $calendar): void
    {
        if ($calendar < 0 || $calendar >= CalendarConstants::CAL_NUM_CALS) {
            throw new \ValueError(
                'cal_days_in_month(): Argument #1 ($calendar) must be a valid calendar ID'
            );
        }
    }

    private static function assertMonthYear(int $month, int $year): void
    {
        if ($month <= 0 || $month > \PHP_INT_MAX - 1) {
            throw new \ValueError(
                \sprintf('cal_days_in_month(): Argument #2 ($month) must be between 1 and %d', \PHP_INT_MAX - 1)
            );
        }
        if ($year > \PHP_INT_MAX - 1) {
            throw new \ValueError(
                \sprintf('cal_days_in_month(): Argument #3 ($year) must be less than %d', \PHP_INT_MAX - 1)
            );
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('cal_days_in_month() is not implemented for JIT in this compiler build (issue #7223)');
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function parseIntArgs(Frame $frame, string $fn): array
    {
        $out = [];
        foreach ($frame->calledArgs as $i => $arg) {
            $var = $arg->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $var->type) {
                throw new \TypeError(\sprintf(
                    '%s(): Argument #%d ($%s) must be of type int, %s given',
                    $fn,
                    $i + 1,
                    match ($i) {
                        0 => 'calendar',
                        1 => 'month',
                        default => 'year',
                    },
                    self::vmTypeName($var->type)
                ));
            }
            $out[] = $var->toInt();
        }

        return [$out[0], $out[1], $out[2]];
    }

    private static function vmTypeName(int $type): string
    {
        return match ($type) {
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            Variable::TYPE_ENUM_CASE => 'object',
            default => 'mixed',
        };
    }
}

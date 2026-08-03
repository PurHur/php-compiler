<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

use PHPCompiler\ext\standard\VmDate;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * easter_date() — midnight local timestamp for Easter Sunday (php-src ext/calendar/easter.c; #7223 / #27356).
 */
final class easter_date extends Internal
{
    public function __construct()
    {
        parent::__construct('easter_date');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 2) {
            throw new \ArgumentCountError('easter_date() accepts at most 2 arguments, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $year = self::resolveYear($frame);
        self::assertEasterYear($year);
        $mode = $argc < 2
            ? CalendarConstants::CAL_EASTER_DEFAULT
            : CalendarArgs::requireInt($frame, 'easter_date', 2, 'mode');
        $frame->returnVar->int(VmCalendar::easterDate($year, $mode));
    }

    private static function assertEasterYear(int $year): void
    {
        $maxYear = intdiv(\PHP_INT_MAX, 5) * 4;
        if ($year <= 0 || $year > $maxYear) {
            throw new \ValueError(
                \sprintf('easter_date(): Argument #1 ($year) must be between 1 and %d', $maxYear)
            );
        }
        if (\PHP_INT_SIZE >= 8) {
            if ($year < 1970) {
                throw new \ValueError('easter_date(): Argument #1 ($year) must be a year after 1970 (inclusive)');
            }
            if ($year > 2000000000) {
                throw new \ValueError(
                    'easter_date(): Argument #1 ($year) must be a year before 2.000.000.000 (inclusive)'
                );
            }
        } elseif ($year < 1970 || $year > 2037) {
            throw new \ValueError('easter_date(): Argument #1 ($year) must be between 1970 and 2037 (inclusive)');
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitEasterDate::invoke($context, ...$args);
    }

    private static function resolveYear(Frame $frame): int
    {
        if (0 === \count($frame->calledArgs)) {
            return self::currentYear();
        }
        $var = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return self::currentYear();
        }
        if (Variable::TYPE_INTEGER !== $var->type) {
            throw new \TypeError(\sprintf(
                'easter_date(): Argument #1 ($year) must be of type ?int, %s given',
                self::vmTypeName($var->type)
            ));
        }

        return $var->toInt();
    }

    private static function currentYear(): int
    {
        $parts = VmDate::getdate(null);
        $yearVar = $parts->find('year');
        if (null === $yearVar) {
            return 1900;
        }

        return $yearVar->toInt();
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

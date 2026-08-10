<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\DateTimeSupport;
use PHPLLVM\Value;

/**
 * date_isodate_set() — procedural DateTime::setISODate wrapper (ext/date/php_date.c, #20016).
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(date_isodate_set)
 */
final class date_isodate_set extends Internal
{
    public function __construct()
    {
        parent::__construct('date_isodate_set');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3) {
            throw new \ArgumentCountError(
                \sprintf('date_isodate_set() expects at least 3 arguments, %d given', $argc)
            );
        }
        if ($argc > 4) {
            throw new \ArgumentCountError(
                \sprintf('date_isodate_set() expects at most 4 arguments, %d given', $argc)
            );
        }
        $datetime = DateTimeSupport::requireDateTime(
            $frame->calledArgs[0],
            'date_isodate_set()',
            1,
            'object',
            $frame->vmContext
        );
        $year = VmMath::parseZParamLongBuiltinArgForFrame($frame, 1, 'date_isodate_set', 2, 'year');
        $week = VmMath::parseZParamLongBuiltinArgForFrame($frame, 2, 'date_isodate_set', 3, 'week');
        $dayOfWeek = (4 === $argc)
            ? VmMath::parseZParamLongBuiltinArgForFrame($frame, 3, 'date_isodate_set', 4, 'dayOfWeek')
            : 1;
        DateTimeSupport::setISODate($datetime, $year, $week, $dayOfWeek);
        BuiltinExecute::writeReturn($frame, static function ($ret) use ($frame): void {
            $ret->copyFrom($frame->calledArgs[0]);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('date_isodate_set() is VM-only in this compiler build');
    }
}

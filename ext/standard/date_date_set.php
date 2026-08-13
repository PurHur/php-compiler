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
 * date_date_set() — procedural DateTime::setDate wrapper (ext/date/php_date.c, JIT/AOT #30747).
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(date_date_set)
 */
final class date_date_set extends Internal
{
    public function __construct()
    {
        parent::__construct('date_date_set');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (4 !== $argc) {
            throw new \ArgumentCountError(
                \sprintf('date_date_set() expects exactly 4 arguments, %d given', $argc)
            );
        }
        $datetime = DateTimeSupport::requireDateTime(
            $frame->calledArgs[0],
            'date_date_set(): Argument #1 ($object)',
            1,
            'object',
            $frame->vmContext
        );
        // Frame index 1 = $year; Zend Argument # is 2 (after $object) — #29863.
        $year = VmMath::parseZParamLongBuiltinArgForFrame($frame, 1, 'date_date_set', 2, 'year');
        $month = VmMath::parseZParamLongBuiltinArgForFrame($frame, 2, 'date_date_set', 3, 'month');
        $day = VmMath::parseZParamLongBuiltinArgForFrame($frame, 3, 'date_date_set', 4, 'day');
        DateTimeSupport::setDate($datetime, $year, $month, $day);
        BuiltinExecute::writeReturn($frame, static function ($ret) use ($frame): void {
            $ret->copyFrom($frame->calledArgs[0]);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitDateMutation::invokeDateSet($context, ...$args);
    }
}

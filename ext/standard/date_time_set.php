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
 * date_time_set() — procedural DateTime::setTime wrapper (ext/date/php_date.c).
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(date_time_set)
 */
final class date_time_set extends Internal
{
    public function __construct()
    {
        parent::__construct('date_time_set');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 5) {
            throw new \ArgumentCountError(
                \sprintf('date_time_set() expects at least 3 arguments, %d given', $argc)
            );
        }
        $datetime = DateTimeSupport::requireDateTime(
            $frame->calledArgs[0],
            'date_time_set(): Argument #1 ($object)',
            1,
            'object',
            $frame->vmContext
        );
        $hour = VmMath::parseZParamLongBuiltinArgForFrame($frame, 1, 'date_time_set', 1, 'hour');
        $minute = VmMath::parseZParamLongBuiltinArgForFrame($frame, 2, 'date_time_set', 2, 'minute');
        $second = 0;
        $microsecond = 0;
        if ($argc >= 4) {
            $second = VmMath::parseZParamLongBuiltinArgForFrame($frame, 3, 'date_time_set', 3, 'second');
        }
        if ($argc >= 5) {
            $microsecond = VmMath::parseZParamLongBuiltinArgForFrame($frame, 4, 'date_time_set', 4, 'microsecond');
        }
        DateTimeSupport::setTime($datetime, $hour, $minute, $second, $microsecond);
        BuiltinExecute::writeReturn($frame, static function ($ret) use ($frame): void {
            $ret->copyFrom($frame->calledArgs[0]);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('date_time_set() is VM-only in this compiler build');
    }
}

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
 * date_timezone_set() — procedural DateTime::setTimezone wrapper (ext/date/php_date.c, #9219, JIT/AOT #30746).
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(date_timezone_set)
 */
final class date_timezone_set extends Internal
{
    public function __construct()
    {
        parent::__construct('date_timezone_set');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(
                \sprintf('date_timezone_set() expects exactly 2 arguments, %d given', $argc)
            );
        }
        $datetime = DateTimeSupport::requireDateTime(
            $frame->calledArgs[0],
            'date_timezone_set(): Argument #1 ($object)',
            1,
            'object',
            $frame->vmContext
        );
        $timezone = DateTimeSupport::requireDateTimeZone(
            $frame->calledArgs[1],
            'date_timezone_set()',
            2,
            'timezone'
        );
        DateTimeSupport::setTimezone($datetime, $timezone);
        BuiltinExecute::writeReturn($frame, static function ($ret) use ($frame): void {
            $ret->copyFrom($frame->calledArgs[0]);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitDateMutation::invokeTimezoneSet($context, ...$args);
    }
}

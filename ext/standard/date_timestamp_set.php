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
 * date_timestamp_set() — procedural DateTime::setTimestamp wrapper (ext/date/php_date.c, #9219).
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(date_timestamp_set)
 */
final class date_timestamp_set extends Internal
{
    public function __construct()
    {
        parent::__construct('date_timestamp_set');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(
                \sprintf('date_timestamp_set() expects exactly 2 arguments, %d given', $argc)
            );
        }
        $datetime = DateTimeSupport::requireDateTime(
            $frame->calledArgs[0],
            'date_timestamp_set(): Argument #1 ($object)',
            1,
            'object',
            $frame->vmContext
        );
        $timestamp = VmMath::parseZParamLongBuiltinArgForFrame(
            $frame,
            1,
            'date_timestamp_set',
            2,
            'timestamp'
        );
        DateTimeSupport::setTimestamp($datetime, $timestamp);
        BuiltinExecute::writeReturn($frame, static function ($ret) use ($frame): void {
            $ret->copyFrom($frame->calledArgs[0]);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('date_timestamp_set() is VM-only in this compiler build (issue #9219)');
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\DateIntervalSupport;
use PHPCompiler\VM\DateTimeSupport;
use PHPLLVM\Value;

/**
 * date_sub() — subtract DateInterval from DateTime in place (ext/date/php_date.c, #4604).
 */
final class date_sub extends Internal
{
    public function __construct()
    {
        parent::__construct('date_sub');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'date_sub() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $dt = DateTimeSupport::requireDateTime(
            $frame->calledArgs[0],
            'date_sub()',
            1,
            'object',
            $frame->vmContext
        );
        $interval = DateIntervalSupport::requireDateInterval(
            $frame->calledArgs[1],
            'date_sub()',
            2,
            'interval'
        );
        DateTimeSupport::subInterval($dt, $interval);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->copyFrom($frame->calledArgs[0]);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitDateMutation::invokeSub($context, ...$args);
    }
}

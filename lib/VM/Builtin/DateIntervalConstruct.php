<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\DateIntervalSupport;

/** DateInterval::__construct(string $spec) — VM (#7278, ext/date/php_date.c). */
final class DateIntervalConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        // User arity excludes $this — php-src zim_DateInterval___construct (#30601).
        $userArgc = max(0, $argc - 1);
        if (1 !== $userArgc) {
            throw new \ArgumentCountError(\sprintf(
                'DateInterval::__construct() expects exactly 1 argument, %d given',
                $userArgc
            ));
        }
        $receiver = DateIntervalSupport::requireDateInterval(
            $frame->calledArgs[0],
            'DateInterval::__construct()'
        );
        // Z_PARAM_STR — caller strict_types → TypeError on null before parse (#29828).
        // Frame arg 1 includes $this; user-visible Argument #1 ($duration).
        $spec = VmString::internalMethodStringArgForFrame(
            $frame,
            1,
            'DateInterval::__construct',
            0,
            'duration'
        );
        DateIntervalSupport::initDateInterval($receiver, $spec);
    }
}

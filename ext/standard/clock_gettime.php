<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * clock_gettime() — PHP 8.3 realtime/monotonic clocks (issue #11624).
 *
 * php-src: ext/standard/hrtime.c — PHP_FUNCTION(clock_gettime)
 */
final class clock_gettime extends Internal
{
    public function __construct()
    {
        parent::__construct('clock_gettime');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(
                \sprintf('clock_gettime() expects at most 1 argument, %d given', $argc)
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $clockId = VmHrtimeNative::CLOCK_REALTIME;
        if (1 === $argc) {
            $clockId = VmClockGettime::resolveClockId($frame->calledArgs[0], 'clock_gettime');
        }
        $result = VmClockGettime::clockGettime($clockId);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->array($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 1) {
            throw new \ArgumentCountError(
                \sprintf('clock_gettime() expects at most 1 argument, %d given', \count($args))
            );
        }

        return JitClockGettime::invoke($context, $args[0] ?? null);
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** time_nanosleep() — sub-second sleep (VM via VmSleepPure; JIT/AOT via SleepJitHelper, #9378). */
final class time_nanosleep extends Internal
{
    public function __construct()
    {
        parent::__construct('time_nanosleep');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'time_nanosleep() expects exactly 2 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $seconds = VmMath::parseIntBuiltinArg($frame->calledArgs[0], 'time_nanosleep', 1, 'seconds');
        $nanoseconds = VmMath::parseIntBuiltinArg($frame->calledArgs[1], 'time_nanosleep', 2, 'nanoseconds');
        $result = VmSleep::timeNanosleep($seconds, $nanoseconds);
        if (\is_array($result)) {
            $frame->returnVar->copyFrom(VmJson::import($result));
        } elseif (\is_bool($result)) {
            $frame->returnVar->bool($result);
        } else {
            $frame->returnVar->bool(false);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \ArgumentCountError(
                'time_nanosleep() expects exactly 2 arguments, '.\count($args).' given'
            );
        }

        return JitSleep::timeNanosleep(
            $context,
            JitSleep::zParamLong($context, $args[0], 'time_nanosleep', 1, 'seconds'),
            JitSleep::zParamLong($context, $args[1], 'time_nanosleep', 2, 'nanoseconds')
        );
    }
}

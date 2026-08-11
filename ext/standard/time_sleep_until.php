<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Value;

/** time_sleep_until() — sleep until absolute timestamp (VM via VmSleepPure; JIT/AOT via SleepJitHelper, #9378). */
final class time_sleep_until extends Internal
{
    public function __construct()
    {
        parent::__construct('time_sleep_until');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'time_sleep_until() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $timestamp = VmMath::parseStrictFloatBuiltinArgForFrame(
            $frame,
            'time_sleep_until',
            1,
            'timestamp'
        );
        if (VmSleepPure::isTimestampInPast($timestamp)) {
            self::emitPastTimestampWarning($frame);
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->bool(VmSleep::timeSleepUntil($timestamp));
    }

    private static function emitPastTimestampWarning(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            VmSleepPure::PAST_TIMESTAMP_WARNING,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame,
            $frame->callSiteLine
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \ArgumentCountError(
                'time_sleep_until() expects exactly 1 argument, '.\count($args).' given'
            );
        }

        return JitSleep::timeSleepUntil($context, $args[0]);
    }
}

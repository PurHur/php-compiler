<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** sleep() — delay execution (VM via VmSleepPure; JIT/AOT via SleepJitHelper PHP #15212). */
final class sleep extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src ext/standard/basic_functions.stub.php — ArgumentCountError (#28691).
        $this->requireExactArgCount($frame, 'sleep', 1);
        $seconds = VmMath::parseZParamLongBuiltinArgForFrame($frame, 0, 'sleep', 1, 'seconds');
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmSleep::sleep($seconds);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->int($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError (AOT) — peer #28228 / #28691.
        if (!$this->requireExactJitArgCount($context, $args, 'sleep', 1)) {
            return $context->constantFromInteger(0);
        }

        return JitSleep::sleep($context, $args[0]);
    }
}

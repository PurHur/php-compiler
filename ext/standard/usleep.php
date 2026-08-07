<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** usleep() — microsecond delay (VM via VmSleepPure; JIT/AOT via SleepJitHelper PHP #15212). */
final class usleep extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src ext/standard/basic_functions.stub.php — ArgumentCountError (#28691).
        $this->requireExactArgCount($frame, 'usleep', 1);
        $microseconds = VmMath::parseZParamLongBuiltinArgForFrame($frame, 0, 'usleep', 1, 'microseconds');
        VmSleep::usleep($microseconds);
        if (null !== $frame->returnVar) {
            $frame->returnVar->null();
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError (AOT) — peer #28228 / #28691.
        if (!$this->requireExactJitArgCount($context, $args, 'usleep', 1)) {
            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }

        return JitSleep::usleep($context, $args[0]);
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pcntl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/** php-src ext/pcntl/pcntl.c PHP_FUNCTION(pcntl_alarm) — issue #19565. */
final class pcntl_alarm extends Internal
{
    public function __construct()
    {
        parent::__construct('pcntl_alarm');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError('pcntl_alarm() expects exactly 1 argument, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $seconds = InternalStrictArg::requireInt($frame, 0, 'pcntl_alarm', 'seconds')->toInt();
        $frame->returnVar->int(VmPcntl::alarm($seconds));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('pcntl_alarm() is not implemented for JIT in this compiler build (issue #19565)');
    }
}

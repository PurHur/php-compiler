<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pcntl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

final class pcntl_sigtimedwait extends Internal
{
    public function __construct()
    {
        parent::__construct('pcntl_sigtimedwait');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 4) {
            throw new \ArgumentCountError(
                'pcntl_sigtimedwait() expects at least 1 argument and at most 4, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (!VmPcntl::available()) {
            throw new \Error('pcntl_sigtimedwait() is not available in this compiler build');
        }
        $signals = VmPcntlArg::parseSignalList($frame->calledArgs[0], 'pcntl_sigtimedwait', 0, 'signals');
        $infoOut = $argc >= 2 ? $frame->calledArgs[1] : null;
        $seconds = 0;
        if ($argc >= 3) {
            $seconds = VmPcntlArg::coerceIntArg($frame->calledArgs[2], 'pcntl_sigtimedwait', 2, 'seconds');
        }
        $nanoseconds = 0;
        if ($argc >= 4) {
            $nanoseconds = VmPcntlArg::coerceIntArg($frame->calledArgs[3], 'pcntl_sigtimedwait', 3, 'nanoseconds');
        }
        $rc = VmPcntl::sigtimedwait($signals, $infoOut, $seconds, $nanoseconds);
        if (false === $rc) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->int($rc);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('pcntl_sigtimedwait() is not implemented for JIT in this compiler build (issue #6545)');
    }
}

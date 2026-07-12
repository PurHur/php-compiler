<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pcntl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

final class pcntl_sigprocmask extends Internal
{
    public function __construct()
    {
        parent::__construct('pcntl_sigprocmask');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(
                'pcntl_sigprocmask() expects at least 2 arguments and at most 3, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (!VmPcntl::available()) {
            throw new \Error('pcntl_sigprocmask() is not available in this compiler build');
        }
        $mode = VmPcntlArg::coerceIntArg($frame->calledArgs[0], 'pcntl_sigprocmask', 0, 'mode');
        $signals = VmPcntlArg::parseSignalList($frame->calledArgs[1], 'pcntl_sigprocmask', 1, 'signals');
        $oldOut = $argc >= 3 ? $frame->calledArgs[2] : null;
        $frame->returnVar->bool(VmPcntl::sigprocmask($mode, $signals, $oldOut));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('pcntl_sigprocmask() is not implemented for JIT in this compiler build (issue #6545)');
    }
}
